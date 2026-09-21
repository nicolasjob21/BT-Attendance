import re

from django import forms
from django.contrib.auth import authenticate
from django.contrib.auth.forms import AuthenticationForm

from .models import User

USERNAME_PATTERN = re.compile(r"^[a-z0-9]+(?:[-_.][a-z0-9]+)*$")


class LoginForm(AuthenticationForm):
    remember = forms.BooleanField(required=False)

    def clean(self):
        username = User.normalize_username(self.cleaned_data.get("username"))
        password = self.cleaned_data.get("password")
        if username and password:
            account = User.all_objects.filter(username=username).first()
            if account and account.deleted_at:
                raise forms.ValidationError("These credentials do not match our records.")
            if account and account.disabled_at:
                raise forms.ValidationError("This account has been disabled. Contact your administrator.")
            self.user_cache = authenticate(self.request, username=username, password=password)
            if self.user_cache is None:
                raise forms.ValidationError("These credentials do not match our records.")
        return self.cleaned_data


def validate_username(value, exclude_pk=None):
    value = User.normalize_username(value)
    if not USERNAME_PATTERN.match(value):
        raise forms.ValidationError("Use lowercase letters, numbers and single - _ . separators, e.g. brite-juan.")
    if User.objects.filter(username=value).exclude(pk=exclude_pk).exists():
        raise forms.ValidationError("That username is already taken.")
    return value
