<?php

final class PhabricatorConpherenceThreadPHIDType extends PhabricatorPHIDType {

  const TYPECONST = 'CONP';

  public function getTypeName() {
    return pht('Conpherence Thread');
  }

  public function newObject() {
    return new ConpherenceThread();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new ConpherenceThreadQuery())
      ->needParticipantCache(true)
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $thread = $objects[$phid];
      $data = $thread->getDisplayData($query->getViewer());
      $handle->setName($data['title']);
      $handle->setFullName($data['title']);
      $handle->setURI('/'.$thread->getMonogram());
    }
  }

  public function canLoadNamedObject($name) {
    return preg_match('/^Z\d*[1-9]\d*$/i', $name);
  }

  public function loadNamedObjects(
    PhabricatorObjectQuery $query,
    array $names) {

    $id_map = array();
    foreach ($names as $name) {
      $id = (int)substr($name, 1);
      $id_map[$id][] = $name;
    }

    $objects = id(new ConpherenceThreadQuery())
      ->setViewer($query->getViewer())
      ->withIDs(array_keys($id_map))
      ->execute();
    $objects = mpull($objects, null, 'getID');

    $results = array();
    foreach ($objects as $id => $object) {
      foreach (idx($id_map, $id, array()) as $name) {
        $results[$name] = $object;
      }
    }

    return $results;
  }

}
