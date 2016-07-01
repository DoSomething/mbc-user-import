'use strict';
/**
 * Task runner for unit tests
 *
 * How To:
 *
 * Using Gulp.js to check your code quality
 * https://marcofranssen.nl/using-gulp-js-to-check-your-code-quality/
 */

var gulp = require('gulp');
var phplint = require('phplint').lint;
var phpunit = require('gulp-phpunit');
var notify  = require('gulp-notify');

gulp.task('phplint', function (cb) {
    phplint(['./src/*.php', '!node_modules/**/*', '!vendor/**/*'],  { limit: 10 }, function (err, stdout, stderr) {
      if (err) {
        cb(err);
        process.exit(1);
      }
      cb();
    });
});

gulp.task('phpunit', function () {
    var options = {debug: false, notify: true};
    gulp.src('phpunit.xml')
      .pipe(phpunit('vendor/bin/phpunit --verbose tests', options))
      .on('error', notify.onError({
        title: "Failed Tests!",
        message: "Error(s) occurred during testing..."
      }));
});

gulp.task('watch', function () {
  gulp.watch(['composer.json', 'phpunit.xml', './**/*.php', './**/*.inc', '!vendor/**/*', '!node_modules/**/*'],
    function (event) {
        console.log('File ' + event.path + ' was ' + event.type + ', running tasks...');
    });
  gulp.watch('composer.json', ['dump-autoload']);
  gulp.watch(['phpunit.xml', './**/*.php', './**/*.inc', '!vendor/**/*', '!node_modules/**/*'], ['phplint', 'phpunit']);
});

gulp.task('default', ['phplint', 'phpunit', 'watch']);
gulp.task('test', ['phplint', 'phpunit']);
