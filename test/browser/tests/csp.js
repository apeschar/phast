test("csp.php", function (assert, document) {
  assert.deepEqual(
    document.defaultView.SCRIPTS,
    ["correct nonce"],
    "Only the script with the (correct) nonce should be executed"
  );
});
