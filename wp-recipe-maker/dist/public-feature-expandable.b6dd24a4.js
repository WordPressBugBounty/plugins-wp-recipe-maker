(self["webpackChunkWPRecipeMakerPublicSplit"] = self["webpackChunkWPRecipeMakerPublicSplit"] || []).push([[990],{

/***/ 2942:
/***/ (() => {

window.WPRecipeMaker = typeof window.WPRecipeMaker === "undefined" ? {} : window.WPRecipeMaker;
window.WPRecipeMaker.expandable = {
  init: () => {
    document.addEventListener('click', function (e) {
      const target = e.target.closest ? e.target.closest('.wprm-expandable-button') : false;
      if (target) {
        WPRecipeMaker.expandable.onClick(target, e);
      }
    }, false);
  },
  onClick: (el, e) => {
    e.preventDefault();
    const showingContent = el.classList.contains('wprm-expandable-button-show');
    let container = el.closest('.wprm-expandable-container');
    if (!container) {
      const containerSeparated = el.closest('.wprm-expandable-container-separated');
      if (containerSeparated) {
        container = containerSeparated;

        // Hide all next elements
        let nextElement = container.nextElementSibling;
        while (nextElement) {
          if (showingContent) {
            nextElement.classList.remove('wprm-expandable-separated-content-collapsed');
          } else {
            nextElement.classList.add('wprm-expandable-separated-content-collapsed');
          }
          nextElement = nextElement.nextElementSibling;
        }
      }
    }
    if (container) {
      if (showingContent) {
        container.classList.remove('wprm-expandable-collapsed');
        container.classList.add('wprm-expandable-expanded');
      } else {
        container.classList.add('wprm-expandable-collapsed');
        container.classList.remove('wprm-expandable-expanded');
      }
    }
  }
};
ready(() => {
  window.WPRecipeMaker.expandable.init();
});
function ready(fn) {
  if (document.readyState != 'loading') {
    fn();
  } else {
    document.addEventListener('DOMContentLoaded', fn);
  }
}

/***/ })

}]);