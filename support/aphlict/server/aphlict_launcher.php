#!/usr/bin/env php
<?php

$root = dirname(dirname(dirname(dirname(__FILE__))));
require_once $root.'/scripts/__init_script__.php';

PhabricatorAphlictManagementWorkflow::requireExtensions();

$args = new PhutilArgumentParser($argv);
$args->setTagline('manage Aphlict notification server');
$args->setSynopsis(<<<EOSYNOPSIS
**aphlict** __command__ [__options__]
    Manage the Aphlict server.

EOSYNOPSIS
  );
$args->parseStandardArguments();

$args->parseWorkflows(array(
  new PhabricatorAphlictManagementStatusWorkflow(),
  new PhabricatorAphlictManagementStartWorkflow(),
  new PhabricatorAphlictManagementStopWorkflow(),
  new PhabricatorAphlictManagementRestartWorkflow(),
  new PhabricatorAphlictManagementDebugWorkflow(),
  new PhutilHelpArgumentWorkflow(),
));
