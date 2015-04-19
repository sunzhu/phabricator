<?php

final class PhabricatorConduitConsoleController
  extends PhabricatorConduitController {

  private $method;

  public function shouldAllowPublic() {
    return true;
  }

  public function willProcessRequest(array $data) {
    $this->method = $data['method'];
  }

  public function processRequest() {

    $request = $this->getRequest();
    $viewer = $request->getUser();

    $method = id(new PhabricatorConduitMethodQuery())
      ->setViewer($viewer)
      ->withMethods(array($this->method))
      ->executeOne();

    if (!$method) {
      return new Aphront404Response();
    }

    $can_call_method = false;

    $status = $method->getMethodStatus();
    $reason = $method->getMethodStatusDescription();
    $errors = array();

    switch ($status) {
      case ConduitAPIMethod::METHOD_STATUS_DEPRECATED:
        $reason = nonempty($reason, pht('This method is deprecated.'));
        $errors[] = pht('Deprecated Method: %s', $reason);
        break;
      case ConduitAPIMethod::METHOD_STATUS_UNSTABLE:
        $reason = nonempty(
          $reason,
          pht(
            'This method is new and unstable. Its interface is subject '.
            'to change.'));
        $errors[] = pht('Unstable Method: %s', $reason);
        break;
    }

    $error_types = $method->getErrorTypes();
    $error_types['ERR-CONDUIT-CORE'] = pht('See error message for details.');
    $error_description = array();
    foreach ($error_types as $error => $meaning) {
      $error_description[] = hsprintf(
        '<li><strong>%s:</strong> %s</li>',
        $error,
        $meaning);
    }
    $error_description = phutil_tag('ul', array(), $error_description);

    $form = new AphrontFormView();
    $form
      ->setUser($request->getUser())
      ->setAction('/api/'.$this->method)
      ->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel('Description')
          ->setValue($method->getMethodDescription()))
      ->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel('Returns')
          ->setValue($method->getReturnType()))
      ->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel('Errors')
          ->setValue($error_description))
      ->appendChild(hsprintf(
        '<p class="aphront-form-instructions">Enter parameters using '.
        '<strong>JSON</strong>. For instance, to enter a list, type: '.
        '<tt>["apple", "banana", "cherry"]</tt>'));

    $params = $method->getParamTypes();
    foreach ($params as $param => $desc) {
      $form->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel($param)
          ->setName("params[{$param}]")
          ->setCaption($desc));
    }

    $must_login = !$viewer->isLoggedIn() &&
                  $method->shouldRequireAuthentication();
    if ($must_login) {
      $errors[] = pht(
        'Login Required: This method requires authentication. You must '.
        'log in before you can make calls to it.');
    } else {
      $form
        ->appendChild(
          id(new AphrontFormSelectControl())
            ->setLabel('Output Format')
            ->setName('output')
            ->setOptions(
              array(
                'human' => 'Human Readable',
                'json'  => 'JSON',
              )))
        ->appendChild(
          id(new AphrontFormSubmitControl())
            ->addCancelButton($this->getApplicationURI())
            ->setValue(pht('Call Method')));
    }

    $header = id(new PHUIHeaderView())
      ->setUser($viewer)
      ->setHeader($method->getAPIMethodName());

    $form_box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setFormErrors($errors)
      ->appendChild($form);

    $content = array();

    $query = $method->newQueryObject();
    if ($query) {
      $orders = $query->getBuiltinOrders();

      $rows = array();
      foreach ($orders as $key => $order) {
        $rows[] = array(
          $key,
          $order['name'],
          implode(', ', $order['vector']),
        );
      }

      $table = id(new AphrontTableView($rows))
        ->setHeaders(
          array(
            pht('Key'),
            pht('Description'),
            pht('Columns'),
          ))
        ->setColumnClasses(
          array(
            'pri',
            '',
            'wide',
          ));
      $content[] = id(new PHUIObjectBoxView())
        ->setHeaderText(pht('Builtin Orders'))
        ->appendChild($table);

      $columns = $query->getOrderableColumns();

      $rows = array();
      foreach ($columns as $key => $column) {
        $rows[] = array(
          $key,
          idx($column, 'unique') ? pht('Yes') : pht('No'),
        );
      }

      $table = id(new AphrontTableView($rows))
        ->setHeaders(
          array(
            pht('Key'),
            pht('Unique'),
          ))
        ->setColumnClasses(
          array(
            'pri',
            'wide',
          ));
      $content[] = id(new PHUIObjectBoxView())
        ->setHeaderText(pht('Column Orders'))
        ->appendChild($table);
    }

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb($method->getAPIMethodName());

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
        $content,
      ),
      array(
        'title' => $method->getAPIMethodName(),
      ));
  }

}
