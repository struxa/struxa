<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Ai\AiSyntheticCommentsGenerator;
use App\Ai\OpenAiApiKeyResolver;
use App\Ai\OpenAiException;
use App\Comment\CommentRepository;
use App\Content\ContentEntryRepository;
use App\Content\ContentTypeRepository;
use App\Flash;
use App\Http\ClientIp;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_COMMENTS]);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $types = new ContentTypeRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $comments = new CommentRepository($pdo);
    $generator = new AiSyntheticCommentsGenerator();

    $publicTypes = static function () use ($types): array {
        $out = [];
        foreach ($types->allOrdered() as $t) {
            if ($t->hasPublicRoute) {
                $out[] = $t;
            }
        }

        return $out;
    };

    $resolveTypeId = static function (Request $request) use ($types, $publicTypes): ?int {
        $list = $publicTypes();
        if ($list === []) {
            return null;
        }
        $q = $request->getQueryParams();
        $fromQuery = isset($q['type']) && is_string($q['type']) && ctype_digit($q['type']) ? (int) $q['type'] : 0;
        if ($fromQuery > 0) {
            foreach ($list as $t) {
                if ($t->id === $fromQuery) {
                    return $t->id;
                }
            }
        }
        $blog = $types->findBySlugCaseInsensitive('blog');
        if ($blog !== null && $blog->hasPublicRoute) {
            return $blog->id;
        }

        return $list[0]->id;
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $pdo,
        $types,
        $entries,
        $comments,
        $generator,
        $publicTypes,
        $resolveTypeId
    ): void {
        $group->get('/tools/ai-comments', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $entries,
            $publicTypes,
            $resolveTypeId
        ): Response {
            $typeId = $resolveTypeId($request);
            $rows = $typeId !== null ? $entries->listPublishedSummariesForContentType($typeId) : [];
            $typeOptions = [];
            foreach ($publicTypes() as $t) {
                $typeOptions[] = ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug];
            }

            return $twig->render($response, 'admin/tools/ai_comments.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'ai_comments',
                'ai_comment_type_options' => $typeOptions,
                'ai_comment_selected_type_id' => $typeId,
                'ai_comment_entries' => $rows,
                'ai_comment_can_generate' => OpenAiApiKeyResolver::canGenerate(),
            ])));
        })->setName('admin.tools.ai_comments');

        $group->post('/tools/ai-comments/generate', function (Request $request, Response $response) use (
            $pdo,
            $types,
            $entries,
            $comments,
            $generator,
            $publicTypes
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.tools.ai_comments');

            if (!OpenAiApiKeyResolver::canGenerate()) {
                Flash::set('error', 'Enable OpenAI and add an API key under System → API keys (and turn on AI draft in AI draft settings if needed).');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $typeId = isset($body['content_type_id']) && ctype_digit((string) $body['content_type_id'])
                ? (int) $body['content_type_id']
                : 0;
            $allowedType = false;
            foreach ($publicTypes() as $t) {
                if ($t->id === $typeId) {
                    $allowedType = true;
                    break;
                }
            }
            if (!$allowedType) {
                Flash::set('error', 'Invalid content type.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $type = $types->findById($typeId);
            if ($type === null || !$type->hasPublicRoute) {
                Flash::set('error', 'Content type not found.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $rawIds = $body['entry_ids'] ?? [];
            if (!is_array($rawIds)) {
                $rawIds = [];
            }
            $entryIds = [];
            foreach ($rawIds as $rid) {
                if (is_string($rid) && ctype_digit($rid)) {
                    $entryIds[] = (int) $rid;
                } elseif (is_int($rid) && $rid > 0) {
                    $entryIds[] = $rid;
                }
            }
            $entryIds = array_values(array_unique(array_filter($entryIds, static fn (int $id): bool => $id > 0)));
            if ($entryIds === []) {
                Flash::set('error', 'Select at least one published article.');

                return $response->withHeader('Location', $back . '?type=' . $typeId)->withStatus(302);
            }
            if (count($entryIds) > 25) {
                Flash::set('error', 'Select at most 25 articles at a time.');

                return $response->withHeader('Location', $back . '?type=' . $typeId)->withStatus(302);
            }

            $sentiment = isset($body['sentiment']) && is_string($body['sentiment']) ? trim($body['sentiment']) : 'mixed';
            $per = isset($body['comments_per_entry']) && is_string($body['comments_per_entry'])
                ? (int) $body['comments_per_entry']
                : 2;
            $per = max(1, min(4, $per));
            $imperfect = !empty($body['imperfect_writing']);

            $summaries = $entries->listPublishedSummariesForContentType($typeId);
            $byId = [];
            foreach ($summaries as $s) {
                $byId[(int) $s['id']] = $s;
            }
            $articles = [];
            foreach ($entryIds as $eid) {
                if (!isset($byId[$eid])) {
                    Flash::set('error', 'One or more selected entries are not published for this type.');

                    return $response->withHeader('Location', $back . '?type=' . $typeId)->withStatus(302);
                }
                $articles[] = [
                    'id' => $eid,
                    'title' => (string) $byId[$eid]['title'],
                    'slug' => (string) $byId[$eid]['slug'],
                    'type_slug' => $type->slug,
                ];
            }

            $apiKey = OpenAiApiKeyResolver::resolve();
            if ($apiKey === '') {
                Flash::set('error', 'No OpenAI API key is configured.');

                return $response->withHeader('Location', $back . '?type=' . $typeId)->withStatus(302);
            }

            try {
                $planned = $generator->generate(
                    $apiKey,
                    OpenAiApiKeyResolver::activeModel(),
                    $articles,
                    $sentiment,
                    $per,
                    $imperfect
                );
            } catch (OpenAiException $e) {
                Flash::set('error', 'OpenAI: ' . $e->getMessage());

                return $response->withHeader('Location', $back . '?type=' . $typeId)->withStatus(302);
            } catch (\Throwable $e) {
                Flash::set('error', 'Generation failed: ' . $e->getMessage());

                return $response->withHeader('Location', $back . '?type=' . $typeId)->withStatus(302);
            }

            if ($planned === []) {
                Flash::set('error', 'The model returned no usable comments. Try again or reduce selection.');

                return $response->withHeader('Location', $back . '?type=' . $typeId)->withStatus(302);
            }

            $ip = ClientIp::fromRequest($request);
            $ua = 'Struxa-AiComments/1';
            $inserted = 0;
            $pdo->beginTransaction();
            try {
                foreach ($planned as $row) {
                    $eid = (int) ($row['entry_id'] ?? 0);
                    $name = trim((string) ($row['author_name'] ?? ''));
                    $text = trim((string) ($row['body'] ?? ''));
                    if ($eid < 1 || $name === '' || $text === '') {
                        continue;
                    }
                    $uniq = bin2hex(random_bytes(8));
                    $emailHash = hash('sha256', strtolower('ai+' . $eid . '+' . $uniq . '@generated.invalid'));
                    $returnTo = '/' . $type->slug . '/' . ($byId[$eid]['slug'] ?? '');
                    if (strlen($returnTo) > 512) {
                        continue;
                    }
                    $bodyHtml = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
                    $clean = [
                        'thread_key' => 'entry:' . $eid,
                        'parent_id' => null,
                        'author_name' => $name,
                        'author_email_hash' => $emailHash,
                        'body' => $text,
                        'body_html' => $bodyHtml,
                        'return_to' => $returnTo,
                    ];
                    $comments->create($clean, $ip, $ua, false);
                    ++$inserted;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                Flash::set('error', 'Could not save comments: ' . $e->getMessage());

                return $response->withHeader('Location', $back . '?type=' . $typeId)->withStatus(302);
            }

            Flash::set('success', $inserted === 1
                ? '1 comment created and approved.'
                : $inserted . ' comments created and approved.');

            $commentsUrl = $parser->urlFor('admin.comments.index') . '?status=approved';

            return $response->withHeader('Location', $commentsUrl)->withStatus(302);
        })->setName('admin.tools.ai_comments.generate');
    })->add($perm)->add($middleware);
};
