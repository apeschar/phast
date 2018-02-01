<?php

namespace Kibo\Phast\Filters\HTML\ScriptsDeferring;

use Kibo\Phast\Common\DOMDocument;
use Kibo\Phast\Filters\HTML\Helpers\BodyFinderTrait;
use Kibo\Phast\Filters\HTML\Helpers\JSDetectorTrait;
use Kibo\Phast\Filters\HTML\HTMLFilter;

class Filter implements HTMLFilter {
    use JSDetectorTrait, BodyFinderTrait;

    private $loadScript = <<<EOS
(function() {
    var done = false;
    document.addEventListener('DOMContentLoaded', function() {
        if (done) {
            return;
        }
        done = true;
        var requestAnimationFrame = window.requestAnimationFrame ||
                                    function (fn) { window.setTimeout(fn, 0); };
        requestAnimationFrame(start);
    });
    function start() {
        var script = document.querySelector('script[data-phast-loader]');
        var parentNode = script.parentNode;
        var newScript = document.createElement('script');
        newScript.textContent = script.textContent;
        parentNode.removeChild(script);
        parentNode.appendChild(newScript);
    }
})();
EOS;


    private $rewriteScript = <<<EOS
(function () {
    var deferreds = [];
    var replace = function (original, rewritten) {
        original.parentNode.insertBefore(rewritten, original);
        original.parentNode.removeChild(original);
    };
    var lastScript;
    var scriptIndex = 0;
    Object.defineProperty(document, 'readyState', {
        configurable: true,
        get: function() {
            return 'loading';
        }
    });
    Array.prototype.forEach.call(document.querySelectorAll('script[type="phast-script"][data-phast-prioritize]'), loadScript);
    Array.prototype.forEach.call(document.querySelectorAll('script[type="phast-script"]'), loadScript);
    function loadScript (el) {
        var script = document.createElement('script');
        Array.prototype.forEach.call(el.attributes, function (attr) {
            script.setAttribute(attr.nodeName, attr.nodeValue);
        });
        script.setAttribute('data-phast-script', ++scriptIndex);
        if (!el.hasAttribute('async')) {
            script.async = false;
        }
        if (el.hasAttribute('data-phast-original-type')) {
            script.setAttribute('type', el.getAttribute('data-phast-original-type'));
            script.removeAttribute('data-phast-original-type');
        } else {
            script.removeAttribute('type');
        }
        if (!el.hasAttribute('src')) {
            script.setAttribute('src', 'data:text/javascript;base64,' + utoa(el.textContent));
            Object.defineProperty(script, 'src', {
                configurable: true,
                get: function() { return ''; }
            });
            script.addEventListener('load', function () {
                delete script['src'];
                script.removeAttribute('src');
                script.textContent = el.textContent;
            });
        }
        if (!el.hasAttribute('async') && !el.hasAttribute('defer')) {
            fakeDocumentWrite(el, script);
        }
        if (el.hasAttribute('defer')) {
            deferreds.push({original: el, rewritten: script});
        } else {
            replace(el, script);
            lastScript = script;
        }
    }
    deferreds.forEach(function (deferred) {
        replace(deferred.original, deferred.rewritten);
    });
    lastScript.addEventListener('load', restoreReadyState);
    lastScript.onerror = restoreReadyState;
    function restoreReadyState() {
        delete document['write'];

        delete document['readyState'];

        if (document.onreadystatechange) {
            exec(document.onreadystatechange, document);
        }

        var dcl = document.createEvent("Event");
        dcl.initEvent("DOMContentLoaded", true, true);
        window.document.dispatchEvent(dcl);
    }
    function exec(func, opt_scopeObject) {
        try {
            func.call(opt_scopeObject || window);
        } catch (err) {}
    }
    function fakeDocumentWrite(originalScript, newScript) {
        var scriptId = newScript.getAttribute('data-phast-script');
        var beforeScript = buildScript(
            '(function () {' +
                'delete document["write"];' +
                'var script = document.querySelector("[data-phast-script=\\\\"' + scriptId + '\\\\"]");' +
                'if (!script) return;' +
                'var beforeScript = document.querySelector("[data-phast-before-script=\\\\"' + scriptId + '\\\\"]");' +
                'if (beforeScript) beforeScript.parentNode.removeChild(beforeScript);' +
                'document.write = function (markup) {' +
                    'script.insertAdjacentHTML("afterend", "" + markup);' +
                '};' +
            '})();'
        );
        beforeScript.setAttribute('data-phast-before-script', scriptId);
        originalScript.parentNode.insertBefore(beforeScript, originalScript);
    }
    function buildScript(body) {
        var script = document.createElement('script');
        script.async = false;
        script.setAttribute('src', 'data:text/javascript;base64,' + utoa(body));
        return script;
    }
    function utoa(str) {
        return btoa(
            encodeURIComponent(str).replace(
                /%([0-9A-F]{2})/g,
                function toSolidBytes(match, p1) {
                    return String.fromCharCode('0x' + p1);
                }
            )
        );
    }
})();
EOS;


    public function transformHTMLDOM(DOMDocument $document) {
        $body = $this->getBodyElement($document);

        foreach ($document->query('//script') as $script) {
            if ($script->hasAttribute('data-phast-no-defer')) {
                $script->removeAttribute('data-phast-no-defer');
            } elseif ($this->isJSElement($script)) {
                $this->rewrite($script);
            }
        }

        $rewriteScript = $document->createElement('script');
        $rewriteScript->setAttribute('type', 'phast-script');
        $rewriteScript->setAttribute('data-phast-loader', '');
        $rewriteScript->textContent = $this->rewriteScript;
        $body->appendChild($rewriteScript);

        $loadScript = $document->createElement('script');
        $loadScript->textContent = $this->loadScript;
        $body->appendChild($loadScript);
    }

    private function rewrite(\DOMElement $script) {
        if ($script->hasAttribute('type')) {
            $script->setAttribute('data-phast-original-type', $script->getAttribute('type'));
        }
        $script->setAttribute('type', 'phast-script');
    }

}
