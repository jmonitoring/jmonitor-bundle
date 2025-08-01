<?php

namespace Jmonitor\JmonitorBundle;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\Mysql\Adapter\DoctrineAdapter;
use Jmonitor\Collector\Mysql\MysqlQueriesCountCollector;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\Collector\Php\PhpCollector;
use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\Jmonitor;
use Jmonitor\JmonitorBundle\Command\CollectorCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class JmonitorBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['enabled']) {
            return;
        }

        if (!$config['project_api_key']) {
            return;
        }

        $container->services()->set(Jmonitor::class)
            ->args([
                $config['project_api_key'],
                $config['http_client'] ? service($config['http_client']) : null,
            ])
        ;

        $collector = $container->services()->set(CollectorCommand::class)
            ->args([
                service(Jmonitor::class),
                $config['logger'] ? service($config['logger']) : null,
            ])
            ->tag('console.command')
        ;

        if ($config['schedule'] === null) {
            if (class_exists('Symfony\Component\Scheduler\Scheduler')) {
                $config['schedule'] = 'default';
            }
        }

        if ($config['schedule']) {
            $collector->tag('scheduler.task', [
                'frequency' => 15,
                'schedule' => $config['schedule'],
                'trigger' => 'every',
                'arguments' => null,
            ]);
        }

        if ($config['collectors']['mysql']['enabled'] ?? false) {
            $container->services()->set(DoctrineAdapter::class)
                ->args([
                    service('doctrine.dbal.default_connection'),
                ])
            ;

            $container->services()->set(MysqlQueriesCountCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                    $config['collectors']['mysql']['db_name']
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.queries_count'])
            ;

            $container->services()->set(MysqlStatusCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.status'])
            ;

            $container->services()->set(MysqlVariablesCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.variables'])
            ;

            $container->services()->get(Jmonitor::class)
                ->call('addCollector', [service(MysqlQueriesCountCollector::class)])
                ->call('addCollector', [service(MysqlStatusCollector::class)])
                ->call('addCollector', [service(MysqlVariablesCollector::class)])
            ;
        }

        if ($config['collectors']['apache']['enabled'] ?? false) {
            $container->services()->set(ApacheCollector::class)
                ->args([
                    $config['collectors']['apache']['server_status_url'],
                ])
                ->tag('jmonitor.collector', ['name' => 'apache'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(ApacheCollector::class)]);
        }

        if ($config['collectors']['system']['enabled'] ?? false) {
            if ($config['collectors']['system']['adapter'] ?? null) {
                $container->services()->set($config['collectors']['system']['adapter']);
            }


            $container->services()->set(SystemCollector::class)
                ->args([
                    $config['collectors']['system']['adapter'] ? service($config['collectors']['system']['adapter']) : null,
                ])
                ->tag('jmonitor.collector', ['name' => 'system'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(SystemCollector::class)]);
        }

        if ($config['collectors']['php']['enabled'] ?? false) {
            $container->services()->set(PhpCollector::class)
                ->tag('jmonitor.collector', ['name' => 'php'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(PhpCollector::class)]);
        }

        if ($config['collectors']['redis']['enabled'] ?? false) {
            $container->services()->set(RedisCollector::class)
                ->args([
                    $config['collectors']['redis']['adapter'] ? service($config['collectors']['redis']['adapter']) : $config['collectors']['redis']['dsn'],
                ])
                ->tag('jmonitor.collector', ['name' => 'redis'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(RedisCollector::class)]);
        }
    }

    /**
     * https://symfony.com/doc/current/components/config/definition.html
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children() // jmonitor
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('project_api_key')->defaultNull()->info('You can find it in your jmonitor.io settings.')->end()
                ->scalarNode('http_client')->defaultNull()->info('Name of a Psr\Http\Client\ClientInterface service. Optional. If null, Psr18ClientDiscovery will be used.')->end()
                // ->scalarNode('cache')->cannotBeEmpty()->defaultValue('cache.app')->info('Name of a Psr\Cache\CacheItemPoolInterface service, default is "cache.app". Required.')->end()
                ->scalarNode('logger')->defaultValue('logger')->info('Name of a Psr\Log\LoggerInterface service, default is "logger". Set null to disable logging.')->end()
                ->scalarNode('schedule')->defaultNull()->info('Name of the schedule used to handle the recurring metrics collection, default is "default" if Scheduler is installed')->end()
                ->arrayNode('collectors')
                    ->addDefaultsIfNotSet() // permet de récup un tableau vide si pas de config
                    // ->useAttributeAsKey()
                    ->children()
                        ->arrayNode('mysql')
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->scalarNode('db_name')->info('Db name of your project.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('apache')
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->scalarNode('server_status_url')->defaultValue('https://localhost/server-status')->cannotBeEmpty()->info('Url of apache mod_status.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('system')
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->scalarNode('adapter')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('redis')
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->scalarNode('dsn')->defaultNull()->info('Redis DSN. See https://symfony.com/doc/current/components/cache/adapters/redis_adapter.html')->end()
                                ->scalarNode('adapter')->defaultNull()->info('Redis or Predis service name')->end()
                            ->end()
                        ->end()
                        ->arrayNode('php')
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(fn($config) => isset($config['enabled']) && $config['enabled'] === true && empty($config['project_api_key']))
                ->thenInvalid('The "project_api_key" must be set if "enabled" is true.')
            ->end()
            ->validate()
                ->ifTrue(function ($config): bool {
                    return
                        isset($config['collectors']['mysql']['enabled'])
                        && $config['collectors']['mysql']['enabled'] === true
                        && empty($config['collectors']['mysql']['db_name']);
                })
            ->thenInvalid('The "db_name" must be set if MySQL collector is enabled.')
            ->end()
            ->validate()
                ->ifTrue(function ($config): bool {
                    return
                        !empty($config['collectors']['redis']['dsn'])
                        && !empty($config['collectors']['redis']['adapter']);
                })
                ->thenInvalid('You cannot set both "dsn" and "adapter" for Redis collector. Please choose one.')
            ->end()
        ;
    }
}
