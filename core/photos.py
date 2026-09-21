"""Decode a base64 "data:image/..." URL captured from the live camera."""

import base64


def decode_data_url(data_url: str):
    """Return (binary, ext) or None when it is not a decodable image data URL."""
    if not data_url or not data_url.startswith("data:image") or "," not in data_url:
        return None
    meta, content = data_url.split(",", 1)
    try:
        binary = base64.b64decode(content, validate=True)
    except Exception:
        return None
    if not binary:
        return None
    return binary, ("png" if "png" in meta else "jpg")
