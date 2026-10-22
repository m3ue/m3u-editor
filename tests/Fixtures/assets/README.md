# Synthetic asset fixtures

These are local, generated test media without provider/user content:

- `sample.mp4`: black 16x16 video, 0.1 seconds, H.264/yuv420p.
- `sample.wav`: silence, 0.1 seconds, mono 8000 Hz PCM.

Regenerate with FFmpeg:

```sh
ffmpeg -f lavfi -i color=c=black:s=16x16:d=0.1 -an -c:v libx264 -pix_fmt yuv420p -movflags +faststart sample.mp4
ffmpeg -f lavfi -i anullsrc=r=8000:cl=mono -t 0.1 -c:a pcm_s16le sample.wav
```

Raster tests reuse the repository's existing `public/placeholder.png` rather than requiring GD image generation.
