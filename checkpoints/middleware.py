import time

from django.conf import settings


class CheckpointSweepMiddleware:
    """When the scheduler isn't running (dev), sweep at most every N seconds from a web request."""

    _last = 0.0

    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        now = time.monotonic()
        if now - CheckpointSweepMiddleware._last > settings.CHECKPOINTS["sweep_throttle_seconds"]:
            CheckpointSweepMiddleware._last = now
            try:
                from .services import CheckpointDispatcher

                CheckpointDispatcher().sweep()
            except Exception:  # never break a page because of the sweep
                pass
        return self.get_response(request)
