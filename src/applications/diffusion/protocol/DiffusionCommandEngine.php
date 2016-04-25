<?php

abstract class DiffusionCommandEngine extends Phobject {

  private $repository;
  private $protocol;
  private $credentialPHID;
  private $argv;
  private $passthru;
  private $connectAsDevice;
  private $sudoAsDaemon;

  public static function newCommandEngine(PhabricatorRepository $repository) {
    $engines = self::newCommandEngines();

    foreach ($engines as $engine) {
      if ($engine->canBuildForRepository($repository)) {
        return id(clone $engine)
          ->setRepository($repository);
      }
    }

    throw new Exception(
      pht(
        'No registered command engine can build commands for this '.
        'repository ("%s").',
        $repository->getDisplayName()));
  }

  private static function newCommandEngines() {
    return id(new PhutilClassMapQuery())
      ->setAncestorClass(__CLASS__)
      ->execute();
  }

  abstract protected function canBuildForRepository(
    PhabricatorRepository $repository);

  abstract protected function newFormattedCommand($pattern, array $argv);
  abstract protected function newCustomEnvironment();

  public function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }

  public function getRepository() {
    return $this->repository;
  }

  public function setProtocol($protocol) {
    $this->protocol = $protocol;
    return $this;
  }

  public function getProtocol() {
    return $this->protocol;
  }

  public function setCredentialPHID($credential_phid) {
    $this->credentialPHID = $credential_phid;
    return $this;
  }

  public function getCredentialPHID() {
    return $this->credentialPHID;
  }

  public function setArgv(array $argv) {
    $this->argv = $argv;
    return $this;
  }

  public function getArgv() {
    return $this->argv;
  }

  public function setPassthru($passthru) {
    $this->passthru = $passthru;
    return $this;
  }

  public function getPassthru() {
    return $this->passthru;
  }

  public function setConnectAsDevice($connect_as_device) {
    $this->connectAsDevice = $connect_as_device;
    return $this;
  }

  public function getConnectAsDevice() {
    return $this->connectAsDevice;
  }

  public function setSudoAsDaemon($sudo_as_daemon) {
    $this->sudoAsDaemon = $sudo_as_daemon;
    return $this;
  }

  public function getSudoAsDaemon() {
    return $this->sudoAsDaemon;
  }

  public function newFuture() {
    $argv = $this->newCommandArgv();
    $env = $this->newCommandEnvironment();

    if ($this->getSudoAsDaemon()) {
      $command = call_user_func_array('csprintf', $argv);
      $command = PhabricatorDaemon::sudoCommandAsDaemonUser($command);
      $argv = array('%C', $command);
    }

    if ($this->getPassthru()) {
      $future = newv('PhutilExecPassthru', $argv);
    } else {
      $future = newv('ExecFuture', $argv);
    }

    $future->setEnv($env);

    return $future;
  }

  private function newCommandArgv() {
    $argv = $this->argv;
    $pattern = $argv[0];
    $argv = array_slice($argv, 1);

    list($pattern, $argv) = $this->newFormattedCommand($pattern, $argv);

    return array_merge(array($pattern), $argv);
  }

  private function newCommandEnvironment() {
    $env = $this->newCommonEnvironment() + $this->newCustomEnvironment();
    foreach ($env as $key => $value) {
      if ($value === null) {
        unset($env[$key]);
      }
    }
    return $env;
  }

  private function newCommonEnvironment() {
    $repository = $this->getRepository();

    $env = array();
      // NOTE: Force the language to "en_US.UTF-8", which overrides locale
      // settings. This makes stuff print in English instead of, e.g., French,
      // so we can parse the output of some commands, error messages, etc.
    $env['LANG'] = 'zh_CN.UTF-8';

      // Propagate PHABRICATOR_ENV explicitly. For discussion, see T4155.
    $env['PHABRICATOR_ENV'] = PhabricatorEnv::getSelectedEnvironmentName();

    $as_device = $this->getConnectAsDevice();
    $credential_phid = $this->getCredentialPHID();

    if ($as_device) {
      $device = AlmanacKeys::getLiveDevice();
      if (!$device) {
        throw new Exception(
          pht(
            'Attempting to build a reposiory command (for repository "%s") '.
            'as device, but this host ("%s") is not configured as a cluster '.
            'device.',
            $repository->getDisplayName(),
            php_uname('n')));
      }

      if ($credential_phid) {
        throw new Exception(
          pht(
            'Attempting to build a repository command (for repository "%s"), '.
            'but the CommandEngine is configured to connect as both the '.
            'current cluster device ("%s") and with a specific credential '.
            '("%s"). These options are mutually exclusive. Connections must '.
            'authenticate as one or the other, not both.',
            $repository->getDisplayName(),
            $device->getName(),
            $credential_phid));
      }
    }


    if ($this->isAnySSHProtocol()) {
      if ($credential_phid) {
        $env['PHABRICATOR_CREDENTIAL'] = $credential_phid;
      }
      if ($as_device) {
        $env['PHABRICATOR_AS_DEVICE'] = 1;
      }
    }

    return $env;
  }

  protected function isSSHProtocol() {
    return ($this->getProtocol() == 'ssh');
  }

  protected function isSVNProtocol() {
    return ($this->getProtocol() == 'svn');
  }

  protected function isSVNSSHProtocol() {
    return ($this->getProtocol() == 'svn+ssh');
  }

  protected function isHTTPProtocol() {
    return ($this->getProtocol() == 'http');
  }

  protected function isHTTPSProtocol() {
    return ($this->getProtocol() == 'https');
  }

  protected function isAnyHTTPProtocol() {
    return ($this->isHTTPProtocol() || $this->isHTTPSProtocol());
  }

  protected function isAnySSHProtocol() {
    return ($this->isSSHProtocol() || $this->isSVNSSHProtocol());
  }

  protected function getSSHWrapper() {
    $root = dirname(phutil_get_library_root('phabricator'));
    return $root.'/bin/ssh-connect';
  }

}
