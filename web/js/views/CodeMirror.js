require('../../css/CodeMirror.css');

var CodeMirror = require('codemirror'),
    _ = require('lodash'),
    CodeMirrorPanel = require('../../templates/CodeMirrorPanel.hbs'),
    $ = require('jquery');
require('codemirror/addon/edit/matchbrackets.js');
require('codemirror/addon/selection/active-line.js');
require('codemirror/addon/display/panel.js');

require('codemirror/mode/htmlmixed/htmlmixed.js');
require('codemirror/mode/javascript/javascript.js');
require('codemirror/mode/xml/xml.js');
require('codemirror/mode/css/css.js');
require('codemirror/mode/clike/clike.js');
require('codemirror/mode/php/php.js');

module.exports = require('backbone').View.extend({
    BREAK_CSS_CLASS: 'CodeMirror-linebreak',

    activeFile: '',

    events: {
        "click a.CodeMirror-step-over" : "_onStepOver",
        "click a.CodeMirror-run" : "_onRun",
    },

    _onRun: function (){
        this.webSocket.emitRun();
    },
    _onStepOver: function (){
        this.webSocket.emitStepOver();
    },

    initialize: function(args) {
        this.webSocket = args.webSocket;
    },
    render: function() {
        this.editor = CodeMirror(this.el, {
            mode:  "php",
            lineNumbers: true,
            matchBrackets: true,
            readOnly: true,
            showCursorWhenSelecting: false,
            gutters: ["CodeMirror-linenumbers", "CodeMirror-breakpoints"]
        });

        this.editor.on("gutterClick", _.bind(function(cm, n) {
            this.webSocket.emitSetBreakpoint(this.activeFile, n + 1);
        }, this));

        var panel= document.createElement('div');
        panel.innerHTML = CodeMirrorPanel();
        panel.className = 'CodeMirror-panel';
        this.editor.addPanel(panel, {position: "bottom"});
    },
    setEditorValue: function(file, value) {
        this.activeFile = file;
        this.editor.setValue(value);
    },
    setBreakpoint: function(file, lineNum) {
        this.editor.setGutterMarker(lineNum - 1, "CodeMirror-breakpoints", this.editor.lineInfo(lineNum - 1).gutterMarkers ? null : function() {
            var marker = document.createElement("div");
            marker.style.color = "red";
            marker.innerHTML = "➜";
            return marker;

        }());
    },
    setBreak: function(file, lineNum) {
        if (file === null || lineNum === null) {
            this.editor.removeLineClass(this.currentBreak, 'background', this.BREAK_CSS_CLASS);
            this.currentBreak = lineNum;
        } else {
            var lineNum = parseInt(lineNum) - 1;
            this.currentBreak = lineNum;
            this.editor.addLineClass(lineNum, 'background', this.BREAK_CSS_CLASS);
        }

    }
});
