const imagemin = require('imagemin');
const imageminPngquant = require('imagemin-pngquant');
const imageminMozJPEG = require('imagemin-mozjpeg');

let directory = process.argv[2];
let jpegQuality = process.argv[3];
let pngQuality = process.argv[4];
let pngSpeed = process.argv[5];

imagemin([directory + '/*.{png,jpg,jpeg}'], directory, {
    use: [
        imageminMozJPEG({quality: jpegQuality}),
        imageminPngquant({quality: pngQuality, speed: pngSpeed})
    ]
}).then(() => {
    console.log('done');
});