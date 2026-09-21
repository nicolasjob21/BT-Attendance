import base64
import io
import shutil
import tempfile

from django.conf import settings
from django.core.management import call_command
from django.test import TestCase, override_settings
from PIL import Image

from accounts.models import User
from employees.models import Employee, Site

HQ = (14.6108, 121.0049)          # inside the seeded head-office geofence
FAR_AWAY = (10.3157, 123.8854)    # Cebu — outside every site


def data_url(size=(64, 64), color=(200, 40, 40)) -> str:
    buf = io.BytesIO()
    Image.new("RGB", size, color).save(buf, format="JPEG")
    return "data:image/jpeg;base64," + base64.b64encode(buf.getvalue()).decode()


_media = tempfile.mkdtemp(prefix="btatt-test-media-")


@override_settings(
    MEDIA_ROOT=f"{_media}/public", PRIVATE_MEDIA_ROOT=f"{_media}/private",
    PASSWORD_HASHERS=["django.contrib.auth.hashers.MD5PasswordHasher"],
    ATTENDANCE={**settings.ATTENDANCE, "softcopy_map": False},
)
class AppTestCase(TestCase):
    """Seeds the same demo data as `manage.py seed` once per test class."""

    @classmethod
    def setUpTestData(cls):
        call_command("seed", verbosity=0)
        cls.admin = User.objects.get(username="brite-admin")
        cls.dev = User.objects.get(username="brite-dev")
        cls.hr = User.objects.get(username="brite-hr")
        cls.tech = User.objects.get(username="brite-tech")
        cls.employee = Employee.objects.get(user=cls.tech)
        cls.hq = Site.objects.get(is_headquarters=True)
        cls.project = Site.objects.get(type="project_site")

    @classmethod
    def tearDownClass(cls):
        super().tearDownClass()
        shutil.rmtree(_media, ignore_errors=True)

    def login(self, user):
        self.client.force_login(user)
        return user

    def assertFlash(self, response, text):
        messages = [str(m) for m in response.context["messages"]] if response.context and "messages" in response.context else []
        self.assertTrue(any(text in m for m in messages), f"flash {text!r} not in {messages}")
