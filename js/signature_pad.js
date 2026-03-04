/**
 * Lightweight Signature Pad Implementation
 *
 * A minimal canvas-based signature pad for capturing handwritten signatures.
 * Produces smooth lines using quadratic bezier curves.
 */

(function(global) {
    'use strict';

    function SignaturePad(canvas, options) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.options = options || {};

        this.penColor = this.options.penColor || '#000000';
        this.lineWidth = this.options.lineWidth || 2.5;
        this.minWidth = this.options.minWidth || 1.5;
        this.maxWidth = this.options.maxWidth || 4.0;

        this._drawing = false;
        this._points = [];
        this._isEmpty = true;
        this._listeners = {};

        this._bindEvents();
    }

    SignaturePad.prototype._bindEvents = function() {
        var self = this;

        // Mouse events
        this.canvas.addEventListener('mousedown', function(e) { self._startStroke(e); });
        this.canvas.addEventListener('mousemove', function(e) { self._continueStroke(e); });
        this.canvas.addEventListener('mouseup', function(e) { self._endStroke(e); });
        this.canvas.addEventListener('mouseleave', function(e) { self._endStroke(e); });

        // Touch events
        this.canvas.addEventListener('touchstart', function(e) {
            e.preventDefault();
            self._startStroke(e.touches[0]);
        });
        this.canvas.addEventListener('touchmove', function(e) {
            e.preventDefault();
            self._continueStroke(e.touches[0]);
        });
        this.canvas.addEventListener('touchend', function(e) {
            e.preventDefault();
            self._endStroke(e);
        });
    };

    SignaturePad.prototype._getPoint = function(e) {
        var rect = this.canvas.getBoundingClientRect();
        var scaleX = this.canvas.width / rect.width;
        var scaleY = this.canvas.height / rect.height;
        return {
            x: (e.clientX - rect.left) * scaleX,
            y: (e.clientY - rect.top) * scaleY,
            time: Date.now()
        };
    };

    SignaturePad.prototype._startStroke = function(e) {
        this._drawing = true;
        this._points = [];
        var point = this._getPoint(e);
        this._points.push(point);
        this._isEmpty = false;

        this.ctx.beginPath();
        this.ctx.fillStyle = this.penColor;
        this.ctx.arc(point.x, point.y, this.lineWidth / 2, 0, Math.PI * 2);
        this.ctx.fill();

        this._emit('beginStroke');
    };

    SignaturePad.prototype._continueStroke = function(e) {
        if (!this._drawing) return;
        var point = this._getPoint(e);
        this._points.push(point);

        if (this._points.length >= 3) {
            var len = this._points.length;
            var p0 = this._points[len - 3];
            var p1 = this._points[len - 2];
            var p2 = this._points[len - 1];

            var midX = (p1.x + p2.x) / 2;
            var midY = (p1.y + p2.y) / 2;

            // Calculate velocity for dynamic width
            var dx = p2.x - p0.x;
            var dy = p2.y - p0.y;
            var dt = Math.max(p2.time - p0.time, 1);
            var velocity = Math.sqrt(dx * dx + dy * dy) / dt;
            var width = Math.max(this.maxWidth / (velocity + 0.5), this.minWidth);
            width = Math.min(width, this.maxWidth);

            this.ctx.beginPath();
            this.ctx.strokeStyle = this.penColor;
            this.ctx.lineWidth = width;
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';
            this.ctx.moveTo(p0.x, p0.y);
            this.ctx.quadraticCurveTo(p1.x, p1.y, midX, midY);
            this.ctx.stroke();
        }
    };

    SignaturePad.prototype._endStroke = function(e) {
        if (!this._drawing) return;
        this._drawing = false;
        this._emit('endStroke');
    };

    SignaturePad.prototype.clear = function() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        this._isEmpty = true;
        this._points = [];
    };

    SignaturePad.prototype.isEmpty = function() {
        return this._isEmpty;
    };

    SignaturePad.prototype.toDataURL = function(type) {
        return this.canvas.toDataURL(type || 'image/png');
    };

    SignaturePad.prototype.addEventListener = function(event, callback) {
        if (!this._listeners[event]) {
            this._listeners[event] = [];
        }
        this._listeners[event].push(callback);
    };

    SignaturePad.prototype._emit = function(event) {
        var callbacks = this._listeners[event] || [];
        for (var i = 0; i < callbacks.length; i++) {
            callbacks[i]();
        }
    };

    // Export
    global.SignaturePad = SignaturePad;

})(typeof window !== 'undefined' ? window : this);
