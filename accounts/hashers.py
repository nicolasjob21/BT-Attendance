"""
Laravel-compatible bcrypt ("$2y$…") hashing.

The Django port shares the `users` table with the Laravel app during the transition
(and with BT-Inventory under the planned single sign-on), so every password we write
must stay readable by PHP's password_verify(). Raw bcrypt is the common format:
no "bcrypt$" prefix, "$2y$" variant, cost 12 — exactly what Laravel produces.
"""

import bcrypt
from django.contrib.auth.hashers import BasePasswordHasher, mask_hash
from django.utils.crypto import constant_time_compare

RAW_BCRYPT_PREFIXES = ("$2y$", "$2a$", "$2b$")


def is_raw_bcrypt(encoded: str) -> bool:
    return bool(encoded) and encoded.startswith(RAW_BCRYPT_PREFIXES)


class LaravelBcryptHasher(BasePasswordHasher):
    algorithm = "laravel_bcrypt"
    rounds = 12

    def salt(self):
        return bcrypt.gensalt(self.rounds).decode()

    def encode(self, password, salt):
        hashed = bcrypt.hashpw(password.encode(), salt.encode()).decode()
        return "$2y$" + hashed[4:]

    def verify(self, password, encoded):
        stored = encoded.replace("$2y$", "$2b$", 1).encode()
        try:
            return constant_time_compare(bcrypt.hashpw(password.encode(), stored), stored)
        except ValueError:
            return False

    def safe_summary(self, encoded):
        return {"algorithm": self.algorithm, "hash": mask_hash(encoded)}

    def must_update(self, encoded):
        return not is_raw_bcrypt(encoded)

    def harden_runtime(self, password, encoded):
        pass

    def decode(self, encoded):
        return {"algorithm": self.algorithm, "hash": encoded}
