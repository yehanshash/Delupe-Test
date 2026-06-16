<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\RecreatesSchemaTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the same-origin BFF used by the Vue SPA.
 * The BFF/SPA require a logged-in dashboard user (not the API key).
 */
final class BffControllerTest extends WebTestCase
{
    use RecreatesSchemaTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->recreateSchema(static::getContainer());

        $user = (new User())
            ->setUsername('tester')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('not-checked-by-loginUser');
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
    }

    public function testShellPageRendersSpaMount(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="app"', $html);
        self::assertStringContainsString('/js/app.js', $html);
    }

    public function testSummaryWorksWhenLoggedIn(): void
    {
        $this->client->request('GET', '/app-api/summary');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(0, $data['count']);
    }

    public function testDashboardRequiresLogin(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/app-api/summary');

        // Unauthenticated → redirected to the login page by the firewall.
        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'http://localhost/login');
    }

    public function testImportSampleThenSummary(): void
    {
        $this->client->request('POST', '/app-api/import');
        self::assertResponseIsSuccessful();

        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($result['ok']);
        self::assertSame(10, $result['imported']);
        self::assertSame(2, $result['failed']);

        $this->client->request('GET', '/app-api/summary');
        $summary = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(10, $summary['count']);
    }

    public function testDocsPageLoadsSwaggerUi(): void
    {
        $this->client->request('GET', '/docs');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('swagger', $html);
        self::assertStringContainsString('/openapi.yaml', $html);
    }

    public function testAdjustPricesValidatesInput(): void
    {
        $this->client->request('POST', '/app-api/adjust-prices', ['percentage' => 'abc']);
        self::assertResponseStatusCodeSame(400);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
    }
}
