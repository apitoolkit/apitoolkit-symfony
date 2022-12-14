<?php

namespace APIToolkit;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class APIToolkitBundle extends AbstractBundle
{
  public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
  {
    // load an XML, PHP or Yaml file
    $container->import('../config/services.yaml');
  }
}
