# Roomtone brand files

- `mark.svg`: the mark (a lava island, a white guest point, the ripples of a song). It is `public/favicon.svg`.
- `roomtone-icon-512.png`: the mark as a 512 px PNG.
- `logo.html` → `roomtone-devpost-logo.png`: the Devpost logo, 1500×1000 (3:2), about 0.7 MB (limit 5 MB).
  Re-render with headless Chrome from the repository root:

  ```bash
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --hide-scrollbars \
    --force-device-scale-factor=1 --window-size=1500,1000 --virtual-time-budget=3000 \
    --screenshot=docs/brand/roomtone-devpost-logo.png "file://$PWD/docs/brand/logo.html"
  ```

- `public/favicon.ico` (16, 32, 48 px) and `public/apple-touch-icon.png` (180 px, square corners: iOS rounds them) are
  made from `mark.svg` the same way: render at 512 px, then resize with `sips -z`. Headless Chrome will not render a
  window under about 500 px, so small sizes come from the large render.
