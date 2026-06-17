/**
 * Firebase sign-in on /login — posts ID token to Struxa for PHPAuth session.
 */
(function () {
  const cfg = window.STRUXA_FIREBASE_CONFIG;
  if (!cfg || !cfg.apiKey) return;

  const btn = document.getElementById("btn-firebase-sign-in");
  const form = document.getElementById("firebase-session-form");
  if (!btn || !form || typeof firebase === "undefined") return;

  try {
    firebase.initializeApp(cfg);
  } catch (e) {
    if (!String(e && e.code).includes("duplicate-app")) {
      console.error(e);
      return;
    }
  }

  const auth = firebase.auth();

  btn.addEventListener("click", function () {
    btn.disabled = true;
    const provider = new firebase.auth.GoogleAuthProvider();
    auth
      .signInWithPopup(provider)
      .then(function (result) {
        return result.user.getIdToken();
      })
      .then(function (idToken) {
        const input = form.querySelector('input[name="id_token"]');
        if (input) input.value = idToken;
        form.submit();
      })
      .catch(function (err) {
        console.error(err);
        btn.disabled = false;
      });
  });
})();
