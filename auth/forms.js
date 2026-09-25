/* Filmmaking tools — small helpers for the account pages.
 *
 * The pages work without this; it only shows things sooner. Loaded as a file
 * from this folder because the pages' Content-Security-Policy allows scripts
 * from here and nowhere else — no inline script, no third parties.
 *
 * data-username-from="<selector of an email input>" on an input or any other
 * element shows, as you type, the username that address will give. It must
 * agree with fm_username_for_email() in auth.php, which is what the server
 * actually uses: everything before the last @, lowercased, if that is a
 * usable username. (Until an @ is typed, the whole of what's there.)
 */
(function () {
  'use strict';

  var VALID = /^[a-z0-9][a-z0-9._+-]{0,63}$/;

  function usernameFor(email) {
    email = String(email || '').trim().toLowerCase();
    var at = email.lastIndexOf('@');
    // Before the @ is typed, show what's there so far: it builds up as you go.
    var local = at === -1 ? email : email.slice(0, at);
    return VALID.test(local) ? local : '';
  }

  var targets = document.querySelectorAll('[data-username-from]');
  Array.prototype.forEach.call(targets, function (out) {
    var source = document.querySelector(out.getAttribute('data-username-from'));
    if (!source) {
      return;
    }
    var empty = out.getAttribute('data-username-empty') || '';
    function update() {
      var name = usernameFor(source.value);
      if ('value' in out && out.tagName === 'INPUT') {
        out.value = name;
      } else {
        out.textContent = name || empty;
      }
    }
    source.addEventListener('input', update);
    update();
  });
})();
