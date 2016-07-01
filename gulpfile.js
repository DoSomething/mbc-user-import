/**
 * Task runner for unit tests
 *
 * How To:
 * 
 * Quick Gulp file to run php-unit tests
 * https://alfrednutile.info/posts/85
 *
 * PHPUnit with gulp 3.5.0
 * https://coderwall.com/p/i3zuwg/phpunit-with-gulp-3-5-0
 */

var phpunit = require('gulp-phpunit');
 
var gulp = require('gulp'),
    notify  = require('gulp-notify'),
    phpunit = require('gulp-phpunit');
 
gulp.task('phpunit', function() {
    var options = {debug: false, notify: true};
    gulp.src('./tests').pipe(
        exec('phpunit -c phpunit.xml tests/',
             function(error, stdout){
               sys.puts(stdout); 
               return false;
             })
        )
        .on('error', notify.onError({
            title: "Failed Tests!",
            message: "Error(s) occurred during testing..."
        }));
});
 
gulp.task('watch', function () {
    gulp.watch('**/*.php', ['phpunit']);
});

gulp.task('default', ['watch']);
