/**
 * @provides javelin-behavior-aphlict-listen
 * @requires javelin-behavior
 *           javelin-aphlict
 *           javelin-stratcom
 *           javelin-request
 *           javelin-uri
 *           javelin-dom
 *           javelin-json
 *           javelin-router
 *           phabricator-notification
 */

JX.behavior('aphlict-listen', function(config) {

  var showing_reload = false;

  function onready() {
    var client = new JX.Aphlict(
      config.id,
      config.server,
      config.port,
      config.subscriptions);

    client
      .setHandler(onaphlictmessage)
      .start();
  }

  JX.Stratcom.listen('aphlict-receive-message', null, function(e) {
    var message = e.getData();

    if (message.type != 'notification') {
      return;
    }

    var request = new JX.Request(
      '/notification/individual/',
      onnotification);

    var routable = request
      .addData({key: message.key})
      .getRoutable();

    routable
      .setType('notification')
      .setPriority(250);

    JX.Router.getInstance().queue(routable);
  });


  // Respond to a notification from the Aphlict notification server. We send
  // a request to Phabricator to get notification details.
  function onaphlictmessage(type, message) {
    switch (type) {
      case 'error':
        new JX.Notification()
          .setContent('(Aphlict) ' + message)
          .alterClassName('jx-notification-error', true)
          .setDuration(0)
          .show();
        break;

      case 'receive':
        JX.Stratcom.invoke('aphlict-receive-message', null, message);
        break;

      default:
        if (__DEV__ && config.debug) {
          var details = message ? JX.JSON.stringify(message) : '';

          new JX.Notification()
            .setContent('(Aphlict) [' + type + '] ' + details)
            .alterClassName('jx-notification-debug', true)
            .setDuration(3000)
            .show();
        }
    }
  }


  // Respond to a response from Phabricator about a specific notification.
  function onnotification(response) {
    if (!response.pertinent) {
      return;
    }

    JX.Stratcom.invoke('notification-panel-update', null, {});

    // Show the notification itself.
    new JX.Notification()
      .setContent(JX.$H(response.content))
      .show();


    // If the notification affected an object on this page, show a
    // permanent reload notification if we aren't already.
    if ((response.primaryObjectPHID in config.pageObjects) && !showing_reload) {
      var reload = new JX.Notification()
        .setContent('Page updated, click to reload.')
        .alterClassName('jx-notification-alert', true)
        .setDuration(0);
      reload.listen('activate', function(e) { JX.$U().go(); });
      reload.show();

      showing_reload = true;
    }
  }


  // Wait for the element to load, and don't do anything if it never loads.
  // If we just go crazy and start making calls to it before it loads, its
  // interfaces won't be registered yet.
  JX.Stratcom.listen('aphlict-component-ready', null, onready);

  // Add Flash object to page
  JX.$(config.containerID).innerHTML =
    '<object classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000">' +
      '<param name="movie" value="' + config.swfURI + '" />' +
      '<param name="allowScriptAccess" value="always" />' +
      '<param name="wmode" value="opaque" />' +
      '<embed src="' + config.swfURI + '" wmode="opaque"' +
        'width="0" height="0" id="' + config.id + '">' +
    '</embed></object>'; //Evan sanctioned
});
