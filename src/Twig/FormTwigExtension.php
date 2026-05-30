<?php

declare(strict_types=1);

namespace App\Twig;

use App\Form\FormFieldRepository;
use App\Form\FormRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FormTwigExtension extends AbstractExtension
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('forms_embed_bundle', $this->embedBundle(...)),
        ];
    }

    /**
     * @return array{form: array<string, mixed>, fields: list<array<string, mixed>>}|null
     */
    public function embedBundle(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $forms = new FormRepository($this->pdo);
        $fields = new FormFieldRepository($this->pdo);
        $form = $forms->findPublishedBySlug($slug);
        if ($form === null) {
            return null;
        }

        return [
            'form' => $form,
            'fields' => $fields->listForForm((int) $form['id']),
        ];
    }
}
