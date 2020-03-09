<?php

namespace Seferov\SymfonyPsalmPlugin\Handler;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Seferov\SymfonyPsalmPlugin\Issue\ContainerDependency;
use Seferov\SymfonyPsalmPlugin\Issue\RepositoryStringShortcut;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ClassHandler implements AfterClassLikeAnalysisInterface, AfterMethodCallAnalysisInterface
{
    /**
     * @psalm-var array<string, string>
     */
    private static $classServiceMap = [];

    /**
     * {@inheritdoc}
     */
    public static function afterStatementAnalysis(Node\Stmt\ClassLike $stmt, ClassLikeStorage $classlike_storage, StatementsSource $statements_source, Codebase $codebase, array &$file_replacements = [])
    {
        foreach ($stmt->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && '__construct' === $stmt->name->name) {
                foreach ($stmt->params as $param) {
                    if ($param->type instanceof Node\Name && ContainerInterface::class === $param->type->getAttributes()['resolvedName']) {
                        IssueBuffer::accepts(
                            new ContainerDependency(new CodeLocation($statements_source, $stmt)),
                            $statements_source->getSuppressedIssues()
                        );
                    }
                }
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public static function afterMethodCallAnalysis(
        Expr $expr,
        string $method_id,
        string $appearing_method_id,
        string $declaring_method_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = [],
        Union &$return_type_candidate = null
    ) {
        switch ($declaring_method_id) {
            case 'Psr\Container\ContainerInterface::get':
            case 'Symfony\Component\DependencyInjection\ContainerInterface::get':
                if ($return_type_candidate && $expr->args[0]->value instanceof ClassConstFetch) {
                    $className = (string) $expr->args[0]->value->class->getAttribute('resolvedName');
                    $return_type_candidate = new Union([new TNamedObject($className)]);
                }
                if (!count(self::$classServiceMap)) {
                    self::$classServiceMap = self::loadServiceFile($codebase);
                }
                if ($return_type_candidate && count(self::$classServiceMap) && $expr->args[0]->value instanceof Node\Scalar\String_) {
                    $serviceName = (string) $expr->args[0]->value->value;
                    if (isset(self::$classServiceMap[$serviceName])) {
                        $return_type_candidate = new Union([new TNamedObject((string) self::$classServiceMap[$serviceName])]);
                    }
                }
                break;
            case 'Symfony\Component\HttpFoundation\Request::getcontent':
                if ($return_type_candidate) {
                    $removeType = 'resource';
                    if (isset($expr->args[0]->value->name->parts[0])) {
                        /** @psalm-suppress MixedArrayAccess */
                        $removeType = 'true' === $expr->args[0]->value->name->parts[0] ? 'string' : 'resource';
                    }
                    $return_type_candidate->removeType($removeType);
                }
                break;
            case 'Doctrine\ORM\EntityManagerInterface::getrepository':
            case 'Doctrine\Persistence\ObjectManager::getrepository':
                if (!$expr->args[0]->value instanceof ClassConstFetch) {
                    IssueBuffer::accepts(
                        new RepositoryStringShortcut(new CodeLocation($statements_source, $expr->args[0]->value)),
                        $statements_source->getSuppressedIssues()
                    );
                }
                break;
        }
    }

    /**
     * @todo don't check every time if containerXml config is not set.
     * @psalm-return array<string, string>
     */
    private static function loadServiceFile(Codebase $codebase): array
    {
        $classServiceMap = [];
        if (count($codebase->config->getPluginClasses())) {
            foreach ($codebase->config->getPluginClasses() as $pluginClass) {
                if ($pluginClass['class'] === str_replace('Handler', 'Plugin', __NAMESPACE__)) {
                    $simpleXmlConfig = $pluginClass['config'];
                }
            }
        }

        if (isset($simpleXmlConfig) && $simpleXmlConfig instanceof \SimpleXMLElement) {
            $serviceFilePath = (string) $simpleXmlConfig->containerXml;
            if (!file_exists($serviceFilePath)) {
                return [];
            }
            $xml = simplexml_load_file($serviceFilePath);
            if (!$xml->services instanceof \SimpleXMLElement) {
                return $classServiceMap;
            }
            $services = $xml->services;
            /** @psalm-suppress MixedAssignment */
            if (count($services)) {
                foreach ($services->service as $serviceObj) {
                    if (isset($serviceObj) && $serviceObj instanceof \SimpleXMLElement) {
                        $serviceAttributes = $serviceObj->attributes();
                        if ($serviceAttributes && isset($serviceAttributes['id']) && isset($serviceAttributes['class'])) {
                            $classServiceMap[(string) $serviceAttributes['id']] = (string) $serviceAttributes['class'];
                        }
                    }
                }
            }
        }

        return $classServiceMap;
    }
}