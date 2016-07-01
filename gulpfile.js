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
var phpunit = require('gulp-phpunit');
var notify  = require('gulp-notify');


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
  gulp.watch(['phpunit.xml', './**/*.php', './**/*.inc', '!vendor/**/*', '!node_modules/**/*'], ['phpunit']);
});

gulp.task('default', ['phpunit', 'watch']);
