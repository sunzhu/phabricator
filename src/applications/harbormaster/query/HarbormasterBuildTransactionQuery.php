<?php

/**
 * @group harbormaster
 */
final class HarbormasterBuildTransactionQuery
  extends PhabricatorApplicationTransactionQuery {

  public function getTemplateApplicationTransaction() {
    return new HarbormasterBuildTransaction();
  }

}
