from django.core.management.base import BaseCommand

from payroll.runner import PayrollRunner


class Command(BaseCommand):
    help = "Keep the current/next payroll periods ready and, on pay day, remind everyone who runs payroll."

    def handle(self, *args, **options):
        r = PayrollRunner().tick()
        self.stdout.write(f"Periods created: {r['periods_created']} · Reminded: {', '.join(r['reminded']) or 'none'}")
