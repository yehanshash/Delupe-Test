<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Container\ContainerInterface;

/**
 * Drops and recreates the ORM schema in the test database so each test starts
 * from a clean slate. Requires a reachable PostgreSQL test database.
 */
trait RecreatesSchemaTrait
{
    protected function recreateSchema(ContainerInterface $container): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return $em;
    }
}
