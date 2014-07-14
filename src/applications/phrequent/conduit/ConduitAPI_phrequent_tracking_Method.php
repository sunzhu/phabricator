<?php

final class ConduitAPI_phrequent_tracking_Method
  extends ConduitAPI_phrequent_Method {

  public function getMethodDescription() {
    return pht(
      'Returns current objects being tracked in Phrequent.');
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function defineParamTypes() {
    return array();
  }

  public function defineReturnType() {
    return 'array';
  }

  public function defineErrorTypes() {
    return array(
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $user = $request->getUser();

    $times = id(new PhrequentUserTimeQuery())
      ->setViewer($user)
      ->needPreemptingEvents(true)
      ->withUserPHIDs(array($user->getPHID()))
      ->execute();

    $now = time();

    $results = id(new PhrequentTimeBlock($times))
      ->getCurrentWorkStack($now);

    return array('data' => $results);
  }

}
