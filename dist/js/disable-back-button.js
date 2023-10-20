/******/ (() => { // webpackBootstrap
var __webpack_exports__ = {};
/*!***************************************!*\
  !*** ./src/js/disable-back-button.js ***!
  \***************************************/
(() => {
  window.history.pushState(null, null, document.URL);
  window.addEventListener("popstate", function() {
    window.history.pushState(null, null, document.URL);
  });
})();

/******/ })()
;
//# sourceMappingURL=disable-back-button.js.map