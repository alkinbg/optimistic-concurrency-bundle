<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use OptimisticConcurrency\Bundle\Attribute\EntityTag;
use OptimisticConcurrency\Bundle\Attribute\RequireIfMatch;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class TestController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[EntityTag('document', scope: 'document-detail')]
    public function show(Document $document): JsonResponse
    {
        $response = new JsonResponse([
            'id' => $document->id(),
            'title' => $document->title(),
        ]);
        $response->setEtag('"controller-value"');

        return $response;
    }

    #[RequireIfMatch('document', scope: 'document-detail')]
    public function update(Document $document, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $title = is_array($payload) && isset($payload['title']) && is_string($payload['title'])
            ? $payload['title']
            : 'updated';

        $document->rename($title);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $document->id(),
            'title' => $document->title(),
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[RequireIfMatch('document', scope: 'document-detail')]
    public function securedUpdate(Document $document): Response
    {
        $document->rename('This controller must not execute for anonymous users');
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[RequireIfMatch('document', scope: 'document-detail')]
    public function race(Document $document): Response
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE documents SET title = ?, version = version + 1 WHERE id = ?',
            ['Concurrent writer', $document->id()],
        );

        $document->rename('This write must lose the race');
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[RequireIfMatch('document', scope: 'document-detail')]
    public function delete(Document $document): Response
    {
        $this->entityManager->remove($document);
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
