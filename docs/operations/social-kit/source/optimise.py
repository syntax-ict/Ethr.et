"""Shrink rendered PNGs to a 256-colour palette, dithered.

Median cut, not Pillow's default octree: octree draws visible rings across the
radial background, median cut does not. A post drops from about 460 KB to 150 KB,
and every platform re-encodes uploads anyway.

    python3 optimise.py ../assets/posts/offline-en.png ...
"""
import sys

from PIL import Image

for path in sys.argv[1:]:
    image = Image.open(path).convert("RGB")
    image.quantize(
        colors=256,
        method=Image.Quantize.MEDIANCUT,
        dither=Image.Dither.FLOYDSTEINBERG,
    ).save(path, "PNG", optimize=True)
print(f"{len(sys.argv) - 1} images optimised")
