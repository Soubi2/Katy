﻿var _createClass = function () { function defineProperties(target, props) { for (var i = 0; i < props.length; i++) { var descriptor = props[i]; descriptor.enumerable = descriptor.enumerable || false; descriptor.configurable = true; if ("value" in descriptor) descriptor.writable = true; Object.defineProperty(target, descriptor.key, descriptor); } } return function (Constructor, protoProps, staticProps) { if (protoProps) defineProperties(Constructor.prototype, protoProps); if (staticProps) defineProperties(Constructor, staticProps); return Constructor; }; }();

function _asyncToGenerator(fn) { return function () { var gen = fn.apply(this, arguments); return new Promise(function (resolve, reject) { function step(key, arg) { try { var info = gen[key](arg); var value = info.value; } catch (error) { reject(error); return; } if (info.done) { resolve(value); } else { return Promise.resolve(value).then(function (value) { step("next", value); }, function (err) { step("throw", err); }); } } return step("next"); }); }; }

function _classCallCheck(instance, Constructor) { if (!(instance instanceof Constructor)) { throw new TypeError("Cannot call a class as a function"); } }

var Xstreamcdn = function () {
    function Xstreamcdn(props) {
        _classCallCheck(this, Xstreamcdn);

        this.libs = props.libs;
        this.settings = props.settings;
        this.state = {};
    }

    _createClass(Xstreamcdn, [{
        key: 'checkLive',
        value: function () {
            var _ref = _asyncToGenerator( /*#__PURE__*/regeneratorRuntime.mark(function _callee(url) {
                var httpRequest, html;
                return regeneratorRuntime.wrap(function _callee$(_context) {
                    while (1) {
                        switch (_context.prev = _context.next) {
                            case 0:
                                httpRequest = this.libs.httpRequest;
                                _context.next = 3;
                                return httpRequest.post(url);

                            case 3:
                                html = _context.sent;
                                return _context.abrupt('return', html);

                            case 5:
                            case 'end':
                                return _context.stop();
                        }
                    }
                }, _callee, this);
            }));

            function checkLive(_x) {
                return _ref.apply(this, arguments);
            }

            return checkLive;
        }()
    }, {
        key: 'getLink',
        value: function () {
            var _ref2 = _asyncToGenerator( /*#__PURE__*/regeneratorRuntime.mark(function _callee3(url) {
                var _libs, httpRequest, cheerio, s, id, u, html, data, results, arrPromise;

                return regeneratorRuntime.wrap(function _callee3$(_context3) {
                    while (1) {
                        switch (_context3.prev = _context3.next) {
                            case 0:
                                _libs = this.libs, httpRequest = _libs.httpRequest, cheerio = _libs.cheerio;
                                _context3.prev = 1;

                                url = url.replace(/#.*$/, '');

                                s = url.split('/');
                                id = s[4].split('?')[0];
                                u = 'https://fembed-hd.com/api/source/' + id;
                                _context3.next = 8;
                                return httpRequest.post(u, {
                                    'User-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.107 Safari/537.36'
                                }, {
                                    r: '',
                                    d: 'fembed-hd.com'
                                });

                            case 8:
                                html = _context3.sent;
                                data = html.data.data;
                                results = [];
                                arrPromise = data.map(function () {
                                    var _ref3 = _asyncToGenerator( /*#__PURE__*/regeneratorRuntime.mark(function _callee2(val) {
                                        var label, isDie;
                                        return regeneratorRuntime.wrap(function _callee2$(_context2) {
                                            while (1) {
                                                switch (_context2.prev = _context2.next) {
                                                    case 0:
                                                        label = val.label;
                                                        _context2.next = 3;
                                                        return httpRequest.isLinkDie(val.file);

                                                    case 3:
                                                        isDie = _context2.sent;


                                                        if (isDie != false) {
                                                            results.push({
                                                                file: val.file,
                                                                label: label,
                                                                type: "direct",
                                                                size: isDie
                                                            });
                                                        }

                                                    case 5:
                                                    case 'end':
                                                        return _context2.stop();
                                                }
                                            }
                                        }, _callee2, this);
                                    }));

                                    return function (_x3) {
                                        return _ref3.apply(this, arguments);
                                    };
                                }());
                                _context3.next = 14;
                                return Promise.all(arrPromise);

                            case 14:
                                return _context3.abrupt('return', {
                                    host: {
                                        url: url,
                                        name: "Xstreamcdn"
                                    },
                                    result: results
                                });

                            case 17:
                                _context3.prev = 17;
                                _context3.t0 = _context3['catch'](1);
                                return _context3.abrupt('return', {
                                    host: {
                                        url: url,
                                        name: "Xstreamcdn"
                                    },
                                    result: []
                                });

                            case 20:
                            case 'end':
                                return _context3.stop();
                        }
                    }
                }, _callee3, this, [[1, 17]]);
            }));

            function getLink(_x2) {
                return _ref2.apply(this, arguments);
            }

            return getLink;
        }()
    }]);

    return Xstreamcdn;
}();

thisSource.function = function (libs, settings) {
    return new Xstreamcdn({
        libs: libs,
        settings: settings
    });
};