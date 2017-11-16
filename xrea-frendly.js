(function () {
  var originWindowAddEventListener = window.addEventListener;
  var originWindowAttachEvent = window.attachEvent;
  var setGlobalAdHTML = function (_, func) {
    var body = document.createElement('body');
    document.head.appendChild(body);
    func();
    window.globalAdHTML = body.innerHTML;
    document.head.removeChild(body);
    window.addEventListener = originWindowAddEventListener;
    window.attachEvent = originWindowAttachEvent;
  };
  window.addEventListener = window.attachEvent = setGlobalAdHTML;
}());
