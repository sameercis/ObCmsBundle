<?php

namespace Ob\CmsBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

use Ob\CmsBundle\DependencyInjection\Compiler\AdminCompilerPass;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class ObCmsExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $container->setParameter('ob_cms.configs', $config);
        $container->setParameter('ob_cms.locales', $config['locales']);

        $container->setParameter('ob_cms.classes', $config['classes']);
        $container->setParameter('ob_cms.classes.controller', $config['classes']['controller']);

        $container->setParameter('ob_cms.templates', $config['templates']);

        $container->addCompilerPass(new AdminCompilerPass());
    }
}
