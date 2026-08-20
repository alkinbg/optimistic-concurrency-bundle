<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional;

use Doctrine\DBAL\Exception;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\RecordingEntityTagProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class OptimisticConcurrencyBundleTest extends FunctionalTestCase
{
    public function testGetEmitsStrongEtag(): void
    {
        $document = $this->createDocument();

        $this->client->request('GET', '/documents/'.$document->id());

        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^"oc1-[A-Za-z0-9_-]{43}"$/', (string) $response->headers->get('ETag'));
    }

    public function testCustomProviderReceivesTheActualRequestAndRepresentationScope(): void
    {
        $document = $this->createDocument();
        $provider = $this->recordingProvider();

        $provider->reset();
        $this->client->request('GET', '/documents/'.$document->id());

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $provider->calls);
        self::assertNotNull($provider->lastContext);
        self::assertSame('document-detail', $provider->lastContext->scope);
        self::assertSame('GET', $provider->lastContext->request->getMethod());
        self::assertSame('/documents/'.$document->id(), $provider->lastContext->request->getPathInfo());
    }

    public function testPatchWithoutIfMatchReturns428(): void
    {
        $document = $this->createDocument();

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"title":"Changed"}',
        );

        $response = $this->client->getResponse();

        self::assertSame(428, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testPatchWithCurrentEtagUpdatesAndReturnsANewEtag(): void
    {
        $document = $this->createDocument();
        $etag = $this->fetchEtag((int) $document->id());

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => $etag,
            ],
            content: '{"title":"Changed"}',
        );

        $response = $this->client->getResponse();
        $newEtag = $response->headers->get('ETag');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotNull($newEtag);
        self::assertNotSame($etag, $newEtag);
        self::assertStringContainsString('"title":"Changed"', (string) $response->getContent());
    }

    public function testStaleEtagReturns412AndCurrentEtag(): void
    {
        $document = $this->createDocument();
        $staleEtag = $this->fetchEtag((int) $document->id());

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => $staleEtag,
            ],
            content: '{"title":"First change"}',
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $currentEtag = (string) $this->client->getResponse()->headers->get('ETag');

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => $staleEtag,
            ],
            content: '{"title":"Second change"}',
        );

        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_PRECONDITION_FAILED, $response->getStatusCode());
        self::assertSame($currentEtag, $response->headers->get('ETag'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testWildcardIfMatchAllowsExistingResource(): void
    {
        $document = $this->createDocument();

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => '*',
            ],
            content: '{"title":"Wildcard"}',
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testWeakEtagDoesNotMatch(): void
    {
        $document = $this->createDocument();
        $etag = $this->fetchEtag((int) $document->id());

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => 'W/'.$etag,
            ],
            content: '{"title":"Changed"}',
        );

        self::assertSame(Response::HTTP_PRECONDITION_FAILED, $this->client->getResponse()->getStatusCode());
    }

    public function testListContainingCurrentStrongEtagMatches(): void
    {
        $document = $this->createDocument();
        $etag = $this->fetchEtag((int) $document->id());

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => '"stale", W/'.$etag.', '.$etag,
            ],
            content: '{"title":"Changed"}',
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testEmptyListElementsAroundCurrentEtagAreIgnored(): void
    {
        $document = $this->createDocument();
        $etag = $this->fetchEtag((int) $document->id());

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => ', '.$etag.',',
            ],
            content: '{"title":"Tolerant list parsing"}',
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testMalformedIfMatchReturns400BeforeEntityTagWork(): void
    {
        $document = $this->createDocument();
        $provider = $this->recordingProvider();
        $provider->reset();

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => 'not-an-etag',
            ],
            content: '{"title":"Changed"}',
        );

        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertNull($response->headers->get('ETag'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(0, $provider->calls);
    }

    public function testSymfonyAuthorizationRunsBeforeIfMatchAndDoesNotDeriveAValidator(): void
    {
        $document = $this->createDocument();
        $provider = $this->recordingProvider();
        $provider->reset();

        $this->client->loginUser(
            new InMemoryUser(
                'regular-user',
                'test-password',
                ['ROLE_USER'],
            ),
        );

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id().'/secured',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IF_MATCH' => '"attacker-stale-validator"',
            ],
            content: '{"title":"Must not execute"}',
        );

        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertNull($response->headers->get('ETag'));
        self::assertSame(0, $provider->calls);
    }

    /**
     * @throws Exception
     */
    public function testDatabaseRaceBecomes412(): void
    {
        $document = $this->createDocument();
        $etag = $this->fetchEtag((int) $document->id());

        $this->client->request(
            'PATCH',
            '/documents/'.$document->id().'/race',
            server: ['HTTP_IF_MATCH' => $etag],
        );

        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_PRECONDITION_FAILED, $response->getStatusCode());
        self::assertNull($response->headers->get('ETag'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $currentTitle = $this->entityManager
    ->getConnection()
    ->fetchOne(
        'SELECT title FROM documents WHERE id = ?',
        [$document->id()],
    );

        self::assertSame('Concurrent writer', $currentTitle);
    }

    public function testDeleteIsRejectedBecauseDoctrineDeleteIsNotVersionGuarded(): void
    {
        $document = $this->createDocument();
        $etag = $this->fetchEtag((int) $document->id());

        $this->client->catchExceptions(false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not support DELETE');

        $this->client->request(
            'DELETE',
            '/documents/'.$document->id(),
            server: ['HTTP_IF_MATCH' => $etag],
        );
    }

    private function fetchEtag(int $id): string
    {
        $this->client->request('GET', '/documents/'.$id);

        $etag = $this->client->getResponse()->headers->get('ETag');

        if (!is_string($etag)) {
            throw new \LogicException('Expected the functional GET response to contain an ETag.');
        }

        return $etag;
    }

    private function recordingProvider(): RecordingEntityTagProvider
    {
        $provider = $this->client->getContainer()->get(RecordingEntityTagProvider::class);

        if (!$provider instanceof RecordingEntityTagProvider) {
            throw new \LogicException('The functional container does not expose the recording entity-tag provider.');
        }

        return $provider;
    }
}
