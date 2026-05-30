<?php

declare(strict_types=1);

use App\Flash;
use App\Form\FormEntryRepository;
use App\Form\FormFieldRepository;
use App\Form\FormFileUploadService;
use App\Form\FormNotificationService;
use App\Form\FormQuizScorer;
use App\Form\FormRenderer;
use App\Form\FormRepository;
use App\Form\FormValidator;
use App\Http\ClientIp;
use App\Security\FileRateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, \PDO $pdo, string $projectRoot, callable $viewData): void {
    $forms = new FormRepository($pdo);
    $fields = new FormFieldRepository($pdo);
    $entries = new FormEntryRepository($pdo);
    $notify = new FormNotificationService();
    $rate = new FileRateLimiter($projectRoot . '/storage/cache/forms_rate');
    $uploader = new FormFileUploadService($projectRoot);

    $loadPublished = static function (string $slug) use ($forms, $fields): ?array {
        $form = $forms->findPublishedBySlug($slug);
        if ($form === null) {
            return null;
        }
        $fieldRows = $fields->listForForm((int) $form['id']);

        return ['form' => $form, 'fields' => $fieldRows];
    };

    $app->get('/forms/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', function (Request $request, Response $response, array $args) use ($twig, $loadPublished, $viewData): Response {
        $slug = (string) ($args['slug'] ?? '');
        $bundle = $loadPublished($slug);
        if ($bundle === null) {
            return $response->withStatus(404);
        }
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $actionUrl = $parser->urlFor('public.forms.submit', ['slug' => $slug]);
        $renderCtx = FormRenderer::context($bundle['form'], $bundle['fields'], $actionUrl, '/forms/' . $slug);

        return $twig->render($response, 'public/forms/form_page.twig', $viewData($renderCtx));
    })->setName('public.forms.show');

    $app->post('/forms/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}/submit', function (Request $request, Response $response, array $args) use (
        $loadPublished,
        $entries,
        $notify,
        $rate,
        $uploader
    ): Response {
        $slug = (string) ($args['slug'] ?? '');
        $bundle = $loadPublished($slug);
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $formUrl = $parser->urlFor('public.forms.show', ['slug' => $slug]);

        if ($bundle === null) {
            Flash::set('error', 'Form not found.');

            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $form = $bundle['form'];
        $fieldRows = $bundle['fields'];
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $returnTo = isset($body['return_to']) && is_string($body['return_to']) && str_starts_with($body['return_to'], '/')
            ? $body['return_to']
            : $formUrl;

        $ip = ClientIp::fromRequest($request);
        if (!$rate->hit('forms_submit_1m', $ip, 8, 60) || !$rate->hit('forms_submit_1h', $ip, 40, 3600)) {
            Flash::set('error', 'Too many submissions. Please wait a few minutes and try again.');

            return $response->withHeader('Location', $returnTo)->withStatus(302);
        }

        /** @var array<string, UploadedFileInterface> $uploaded */
        $uploaded = [];
        foreach ($request->getUploadedFiles() as $key => $file) {
            if (is_string($key) && $file instanceof UploadedFileInterface) {
                $uploaded[$key] = $file;
            }
        }

        $validated = FormValidator::validateSubmission(
            $body,
            $fieldRows,
            !empty($form['honeypot_enabled']),
            $uploaded,
            $uploader,
            (int) $form['id']
        );
        if ($validated['ok'] !== true) {
            Flash::set('error', $validated['error']);

            return $response->withHeader('Location', $returnTo)->withStatus(302);
        }

        $quizResult = FormQuizScorer::score($form, $fieldRows, $validated['clean']);
        $quizPayload = ($form['form_type'] ?? 'standard') === 'quiz'
            ? ['score' => $quizResult['score'], 'max_score' => $quizResult['max_score'], 'passed' => $quizResult['passed']]
            : null;

        $referrer = $request->getHeaderLine('Referer');
        $entryId = $entries->create(
            (int) $form['id'],
            $ip,
            $request->getHeaderLine('User-Agent'),
            $referrer,
            $validated['clean'],
            $quizPayload
        );

        try {
            $notify->sendAdminNotification($form, $validated['clean'], $fieldRows);
        } catch (\Throwable) {
            // Non-fatal: entry is stored even if mail fails.
        }

        if (($form['confirmation_type'] ?? 'message') === 'redirect' && !empty($form['confirmation_redirect_url'])) {
            return $response->withHeader('Location', (string) $form['confirmation_redirect_url'])->withStatus(302);
        }

        if (($form['form_type'] ?? 'standard') === 'quiz') {
            Flash::set('success', FormQuizScorer::confirmationMessage($form, $quizResult));
        } else {
            $msg = trim((string) ($form['confirmation_message'] ?? ''));
            Flash::set('success', $msg !== '' ? $msg : 'Thanks — your submission was received.');
        }

        return $response->withHeader('Location', $returnTo . (str_contains($returnTo, '#') ? '' : '#form-entry-' . $entryId))->withStatus(302);
    })->setName('public.forms.submit');
};
