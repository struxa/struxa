<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Flash;
use App\Form\FormEntryRepository;
use App\Form\FormFieldRepository;
use App\Form\FormFieldType;
use App\Form\FormPageGrouper;
use App\Form\FormRepository;
use App\Form\FormSlugger;
use App\Form\FormTemplateCatalog;
use App\Form\FormValidator;
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
    $forms = new FormRepository($pdo);
    $fields = new FormFieldRepository($pdo);
    $entries = new FormEntryRepository($pdo);

    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_FORMS]);

    $adminContext = static fn (): array => $viewData(['admin_nav' => 'forms']);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $jsonResponse = static function (Response $response, array $payload, int $status = 200): Response {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    };

    $app->group('/admin/forms', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $forms,
        $fields,
        $entries,
        $adminContext,
        $withCmsUser,
        $pdo,
        $jsonResponse
    ): void {
        $group->get('', function (Request $request, Response $response) use ($twig, $forms, $adminContext, $withCmsUser): Response {
            return $twig->render($response, 'admin/forms/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'form_rows' => $forms->listForAdmin(),
            ])));
        })->setName('admin.forms.index');

        $group->get('/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser): Response {
            return $twig->render($response, 'admin/forms/new.twig', $withCmsUser($request, array_merge($adminContext(), [
                'templates' => FormTemplateCatalog::all(),
            ])));
        })->setName('admin.forms.new');

        $group->post('/new', function (Request $request, Response $response) use ($forms, $fields, $pdo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $templateKey = isset($body['template']) && is_string($body['template']) ? trim($body['template']) : 'blank';
            if (!FormTemplateCatalog::isValid($templateKey)) {
                $templateKey = 'blank';
            }
            $template = FormTemplateCatalog::all()[$templateKey];
            $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : $template['name'];
            if ($name === '') {
                Flash::set('error', 'Form name is required.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.new'))->withStatus(302);
            }
            $slug = FormSlugger::ensureUnique($pdo, FormSlugger::fromName($name));
            $formId = $forms->createFromTemplate($name, $slug, $templateKey, $template['fields'], $template['form'] ?? []);
            $fields->ensureHoneypot($formId);
            Flash::set('success', 'Form created. Add fields and publish when ready.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $formId]))->withStatus(302);
        })->setName('admin.forms.create');

        $group->get('/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $forms, $fields, $adminContext, $withCmsUser): Response {
            $id = (int) ($args['id'] ?? 0);
            $form = $forms->findById($id);
            if ($form === null) {
                Flash::set('error', 'Form not found.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.index'))->withStatus(302);
            }
            $fieldRows = $fields->listForForm($id);
            $visibleFields = array_values(array_filter($fieldRows, static fn (array $f): bool => ($f['field_type'] ?? '') !== FormFieldType::HONEYPOT));
            $fieldKeys = array_values(array_filter(array_map(static fn (array $f): string => (string) ($f['field_key'] ?? ''), $visibleFields)));

            return $twig->render($response, 'admin/forms/edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                'form_row' => $form,
                'field_rows' => $visibleFields,
                'field_types' => FormFieldType::labels(),
                'field_key_options' => $fieldKeys,
                'max_page_number' => FormPageGrouper::maxPageNumber($fieldRows),
                'is_quiz' => ($form['form_type'] ?? 'standard') === 'quiz',
                'public_url' => '/forms/' . rawurlencode((string) $form['slug']),
            ])));
        })->setName('admin.forms.edit');

        $group->post('/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($forms, $fields, $pdo): Response {
            $id = (int) ($args['id'] ?? 0);
            $form = $forms->findById($id);
            if ($form === null) {
                Flash::set('error', 'Form not found.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.index'))->withStatus(302);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $validated = FormValidator::validateFormSettings($body, $id, $pdo);
            if ($validated['ok'] !== true) {
                Flash::set('error', $validated['error']);
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]))->withStatus(302);
            }
            $forms->update($id, $validated['clean']);
            if (!empty($validated['clean']['honeypot_enabled'])) {
                $fields->ensureHoneypot($id);
            }
            Flash::set('success', 'Form saved.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]))->withStatus(302);
        })->setName('admin.forms.update');

        $group->post('/{id:[0-9]+}/fields/add', function (Request $request, Response $response, array $args) use ($forms, $fields): Response {
            $id = (int) ($args['id'] ?? 0);
            $form = $forms->findById($id);
            if ($form === null) {
                Flash::set('error', 'Form not found.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.index'))->withStatus(302);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $isQuiz = ($form['form_type'] ?? 'standard') === 'quiz';
            $validated = FormValidator::validateFieldInput($body, $id, $isQuiz);
            if ($validated['ok'] !== true) {
                Flash::set('error', $validated['error']);
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]) . '#add-field')->withStatus(302);
            }
            $existing = $fields->listForForm($id);
            $maxOrder = 0;
            foreach ($existing as $f) {
                $maxOrder = max($maxOrder, (int) ($f['sort_order'] ?? 0));
            }
            $validated['clean']['sort_order'] = $maxOrder + 10;
            $fields->create($validated['clean']);
            Flash::set('success', 'Field added.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]))->withStatus(302);
        })->setName('admin.forms.fields.add');

        $group->post('/{id:[0-9]+}/fields/{fieldId:[0-9]+}/update', function (Request $request, Response $response, array $args) use ($forms, $fields): Response {
            $id = (int) ($args['id'] ?? 0);
            $fieldId = (int) ($args['fieldId'] ?? 0);
            $form = $forms->findById($id);
            $existing = $fields->findById($fieldId, $id);
            if ($form === null || $existing === null) {
                Flash::set('error', 'Field not found.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]))->withStatus(302);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $body['field_key'] = $existing['field_key'];
            $body['field_type'] = $existing['field_type'];
            $body['sort_order'] = $existing['sort_order'];
            $isQuiz = ($form['form_type'] ?? 'standard') === 'quiz';
            $validated = FormValidator::validateFieldInput($body, $id, $isQuiz);
            if ($validated['ok'] !== true) {
                Flash::set('error', $validated['error']);
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]) . '#field-' . $fieldId)->withStatus(302);
            }
            $fields->update($fieldId, $id, $validated['clean']);
            Flash::set('success', 'Field updated.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]) . '#field-' . $fieldId)->withStatus(302);
        })->setName('admin.forms.fields.update');

        $group->post('/{id:[0-9]+}/fields/reorder', function (Request $request, Response $response, array $args) use ($forms, $fields, $jsonResponse): Response {
            $id = (int) ($args['id'] ?? 0);
            if ($forms->findById($id) === null) {
                return $response->withStatus(404);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $order = isset($body['field_order']) && is_array($body['field_order']) ? $body['field_order'] : [];
            $ids = array_map('intval', $order);
            $fields->reorder($id, $ids);

            $wantsJson = ($body['_format'] ?? '') === 'json'
                || str_contains($request->getHeaderLine('Accept'), 'application/json');
            if ($wantsJson) {
                return $jsonResponse($response, ['ok' => true]);
            }

            Flash::set('success', 'Field order updated.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]))->withStatus(302);
        })->setName('admin.forms.fields.reorder');

        $group->post('/{id:[0-9]+}/fields/{fieldId:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($forms, $fields): Response {
            $id = (int) ($args['id'] ?? 0);
            $fieldId = (int) ($args['fieldId'] ?? 0);
            if ($forms->findById($id) === null) {
                Flash::set('error', 'Form not found.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.index'))->withStatus(302);
            }
            $fields->delete($fieldId, $id);
            Flash::set('success', 'Field removed.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.edit', ['id' => $id]))->withStatus(302);
        })->setName('admin.forms.fields.delete');

        $group->post('/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($forms): Response {
            $id = (int) ($args['id'] ?? 0);
            $forms->delete($id);
            Flash::set('success', 'Form deleted.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.index'))->withStatus(302);
        })->setName('admin.forms.delete');

        $group->get('/{id:[0-9]+}/entries', function (Request $request, Response $response, array $args) use ($twig, $forms, $entries, $adminContext, $withCmsUser): Response {
            $id = (int) ($args['id'] ?? 0);
            $form = $forms->findById($id);
            if ($form === null) {
                Flash::set('error', 'Form not found.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.index'))->withStatus(302);
            }
            $q = $request->getQueryParams();
            $status = isset($q['status']) && is_string($q['status']) ? trim($q['status']) : 'all';

            return $twig->render($response, 'admin/forms/entries.twig', $withCmsUser($request, array_merge($adminContext(), [
                'form_row' => $form,
                'entry_status' => $status,
                'entry_rows' => $entries->listForForm($id, $status),
                'entry_new_count' => $entries->countByStatus($id, 'new'),
                'entry_read_count' => $entries->countByStatus($id, 'read'),
                'entry_spam_count' => $entries->countByStatus($id, 'spam'),
            ])));
        })->setName('admin.forms.entries');

        $group->get('/{id:[0-9]+}/entries/export', function (Request $request, Response $response, array $args) use ($forms, $entries): Response {
            $id = (int) ($args['id'] ?? 0);
            $form = $forms->findById($id);
            if ($form === null) {
                return $response->withStatus(404);
            }
            $rows = $entries->exportRows($id);
            $headers = ['id', 'created_at', 'status', 'ip_address'];
            foreach ($rows as $row) {
                foreach (array_keys($row) as $k) {
                    if (!in_array($k, $headers, true)) {
                        $headers[] = $k;
                    }
                }
            }
            $csv = fopen('php://temp', 'r+');
            if ($csv === false) {
                return $response->withStatus(500);
            }
            fputcsv($csv, $headers);
            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $h) {
                    $line[] = $row[$h] ?? '';
                }
                fputcsv($csv, $line);
            }
            rewind($csv);
            $content = stream_get_contents($csv) ?: '';
            fclose($csv);
            $filename = 'form-' . preg_replace('/[^a-z0-9-]+/', '-', (string) $form['slug']) . '-entries.csv';

            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->write($content);
        })->setName('admin.forms.entries.export');

        $group->get('/{id:[0-9]+}/entries/{entryId:[0-9]+}', function (Request $request, Response $response, array $args) use ($twig, $forms, $entries, $fields, $adminContext, $withCmsUser): Response {
            $id = (int) ($args['id'] ?? 0);
            $entryId = (int) ($args['entryId'] ?? 0);
            $form = $forms->findById($id);
            $entry = $entries->findById($entryId, $id);
            if ($form === null || $entry === null) {
                Flash::set('error', 'Entry not found.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.index'))->withStatus(302);
            }
            if (($entry['status'] ?? '') === 'new') {
                $entries->setStatus($entryId, $id, 'read');
                $entry['status'] = 'read';
            }
            $values = $entries->valuesForEntry($entryId);
            $fieldLabels = [];
            foreach ($fields->listForForm($id) as $f) {
                $fieldLabels[(string) $f['field_key']] = (string) $f['label'];
            }

            return $twig->render($response, 'admin/forms/entry.twig', $withCmsUser($request, array_merge($adminContext(), [
                'form_row' => $form,
                'entry_row' => $entry,
                'value_rows' => $values,
                'field_labels' => $fieldLabels,
            ])));
        })->setName('admin.forms.entry');

        $group->post('/{id:[0-9]+}/entries/{entryId:[0-9]+}/status', function (Request $request, Response $response, array $args) use ($entries, $forms): Response {
            $id = (int) ($args['id'] ?? 0);
            $entryId = (int) ($args['entryId'] ?? 0);
            if ($forms->findById($id) === null) {
                Flash::set('error', 'Form not found.');
                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.index'))->withStatus(302);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $status = isset($body['status']) && is_string($body['status']) ? trim($body['status']) : 'read';
            $entries->setStatus($entryId, $id, $status);
            Flash::set('success', 'Entry updated.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.entries', ['id' => $id]))->withStatus(302);
        })->setName('admin.forms.entry.status');

        $group->post('/{id:[0-9]+}/entries/{entryId:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($entries, $forms): Response {
            $id = (int) ($args['id'] ?? 0);
            $entryId = (int) ($args['entryId'] ?? 0);
            $entries->delete($entryId, $id);
            Flash::set('success', 'Entry deleted.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.forms.entries', ['id' => $id]))->withStatus(302);
        })->setName('admin.forms.entry.delete');
    })->add($perm)->add($middleware);
};
