from django.core.management.base import BaseCommand

from checkpoints.services import CheckpointDispatcher


class Command(BaseCommand):
    help = "Start scheduled checkpoint campaigns and expire lapsed ones."

    def handle(self, *args, **options):
        r = CheckpointDispatcher().tick()
        self.stdout.write(f"Started: {r['started']} · Expired: {r['expired']} · Missed: {r['missed']}")
