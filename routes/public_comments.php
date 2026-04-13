<?php

declare(strict_types=1);

use App\Comment\CommentRepository;
use App\Comment\CommentValidator;
use App\Flash;
use App\Http\ClientIp;
use App\Security\FileRateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return static function (App $app, \PDO $pdo, string $projectRoot): void {
    $repo = new CommentRepository($pdo);
    $rate = new FileRateLimiter($projectRoot . '/storage/cache/comment_rate');
    $requireApproval = !in_array(
        strtolower(trim((string) ($_ENV['CMS_COMMENTS_AUTO_APPROVE'] ?? '0'))),
        ['1', 'true', 'yes', 'on'],
        true
    );

    $app->post('/comments/post', function (Request $request, Response $response) use ($repo, $rate, $requireApproval): Response {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $validated = CommentValidator::validate($body);
        $returnTo = isset($body['return_to']) && is_string($body['return_to']) && str_starts_with($body['return_to'], '/')
            ? $body['return_to']
            : '/';
        $loc = $returnTo . '#comments';

        if ($validated['ok'] !== true) {
            Flash::set('error', $validated['error']);

            return $response->withHeader('Location', $loc)->withStatus(302);
        }

        $clean = $validated['clean'];
        $ip = ClientIp::fromRequest($request);
        if (!$rate->hit('comment_post_1m', $ip, 6, 60) || !$rate->hit('comment_post_1h', $ip, 30, 3600)) {
            Flash::set('error', 'Too many comment attempts. Please slow down and try again in a few minutes.');

            return $response->withHeader('Location', $loc)->withStatus(302);
        }

        if ($repo->hasRecentDuplicate($clean['thread_key'], $clean['author_email_hash'], $clean['body'], 180)) {
            Flash::set('error', 'That comment looks duplicated. Wait a moment before reposting.');

            return $response->withHeader('Location', $loc)->withStatus(302);
        }

        try {
            $repo->create($clean, $ip, $request->getHeaderLine('User-Agent'), $requireApproval);
        } catch (\RuntimeException $e) {
            Flash::set('error', $e->getMessage());

            return $response->withHeader('Location', $loc)->withStatus(302);
        }

        Flash::set('success', $requireApproval
            ? 'Comment submitted. It will appear after moderation.'
            : 'Comment posted.');

        return $response->withHeader('Location', $loc)->withStatus(302);
    })->setName('public.comments.post');
};
