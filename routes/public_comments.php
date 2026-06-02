<?php

declare(strict_types=1);

use App\Comment\CommentLikeRepository;
use App\Comment\CommentRepository;
use App\Comment\CommentValidator;
use App\Comment\CommentVisibility;
use App\Flash;
use App\Http\ClientIp;
use App\Http\SafeRedirectPath;
use App\Security\FileRateLimiter;
use App\Auth\PhpAuthUsernameRepository;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;

return static function (App $app, \PDO $pdo, string $projectRoot, Auth $auth): void {
    $repo = new CommentRepository($pdo);
    $likes = new CommentLikeRepository($pdo);
    $rate = new FileRateLimiter($projectRoot . '/storage/cache/comment_rate');
    $likeRate = new FileRateLimiter($projectRoot . '/storage/cache/comment_like_rate');
    $requireApproval = !in_array(
        strtolower(trim((string) ($_ENV['CMS_COMMENTS_AUTO_APPROVE'] ?? '0'))),
        ['1', 'true', 'yes', 'on'],
        true
    );

    $accountDisplay = static function () use ($auth, $pdo): array {
        if (!$auth->isLogged()) {
            return ['', '', 0];
        }
        $uid = (int) $auth->getCurrentUID();
        $email = (string) ($auth->getCurrentUser()['email'] ?? '');
        $username = '';
        if ($uid > 0) {
            try {
                $username = PhpAuthUsernameRepository::findByUserId($pdo, $uid) ?? '';
            } catch (\PDOException) {
                $username = '';
            }
        }

        return [$email, $username, $uid];
    };

    $app->post('/comments/post', function (Request $request, Response $response) use (
        $repo,
        $rate,
        $requireApproval,
        $auth,
        $accountDisplay,
        $pdo
    ): Response {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $rawReturnTo = isset($body['return_to']) && is_string($body['return_to']) ? $body['return_to'] : null;
        $returnTo = SafeRedirectPath::afterLogin($rawReturnTo, '/');
        $loc = $returnTo . '#comments';
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $loginUrl = $parser->urlFor('login') . '?' . http_build_query(['next' => $returnTo]);

        if (!$auth->isLogged()) {
            Flash::set('error', 'Sign in to post a comment.');

            return $response->withHeader('Location', $loginUrl)->withStatus(302);
        }

        [$email, $username, $uid] = $accountDisplay();
        if ($uid < 1 || $email === '') {
            Flash::set('error', 'Sign in to post a comment.');

            return $response->withHeader('Location', $loginUrl)->withStatus(302);
        }

        $validated = CommentValidator::validateAuthenticated($body, $uid, $email, $username);
        if ($validated['ok'] !== true) {
            Flash::set('error', $validated['error']);

            return $response->withHeader('Location', $loc)->withStatus(302);
        }

        $clean = $validated['clean'];
        if (!CommentVisibility::isThreadAllowed($pdo, $clean['thread_key'])) {
            Flash::set('error', 'Comments are not enabled for this page.');

            return $response->withHeader('Location', $loc)->withStatus(302);
        }

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

    $app->post('/comments/like', function (Request $request, Response $response) use ($repo, $likes, $likeRate, $auth, $accountDisplay, $pdo): Response {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $rawReturnTo = isset($body['return_to']) && is_string($body['return_to']) ? $body['return_to'] : null;
        $returnTo = SafeRedirectPath::afterLogin($rawReturnTo, '/');
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $loginUrl = $parser->urlFor('login') . '?' . http_build_query(['next' => $returnTo]);

        if (!$auth->isLogged()) {
            Flash::set('error', 'Sign in to like comments.');

            return $response->withHeader('Location', $loginUrl)->withStatus(302);
        }

        [, , $uid] = $accountDisplay();
        if ($uid < 1) {
            Flash::set('error', 'Sign in to like comments.');

            return $response->withHeader('Location', $loginUrl)->withStatus(302);
        }

        $validated = CommentValidator::validateLikeRequest($body);
        if ($validated['ok'] !== true) {
            Flash::set('error', $validated['error']);

            return $response->withHeader('Location', $returnTo . '#comments')->withStatus(302);
        }
        $c = $validated['clean'];
        if (!CommentVisibility::isThreadAllowed($pdo, $c['thread_key'])) {
            Flash::set('error', 'Comments are not enabled for this page.');

            return $response->withHeader('Location', $returnTo . '#comments')->withStatus(302);
        }
        $row = $repo->findApprovedInThread($c['comment_id'], $c['thread_key']);
        if ($row === null) {
            Flash::set('error', 'That comment is not available.');

            return $response->withHeader('Location', $returnTo . '#comments')->withStatus(302);
        }

        $ip = ClientIp::fromRequest($request);
        if (!$likeRate->hit('comment_like_1m', (string) $uid . ':' . $ip, 40, 60)) {
            Flash::set('error', 'Too many like actions. Try again shortly.');

            return $response->withHeader('Location', $returnTo . '#comment-' . $c['comment_id'])->withStatus(302);
        }

        $likes->toggle($c['comment_id'], $uid);

        return $response
            ->withHeader('Location', $returnTo . '#comment-' . $c['comment_id'])
            ->withStatus(302);
    })->setName('public.comments.like');
};
