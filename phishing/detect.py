#!/usr/bin/env python3
# LEXSHIELD_PYTHON_PHISHING
"""Stdlib-only phishing URL scanner. Prints one JSON object to stdout."""

from __future__ import annotations

import argparse
import json
import math
import re
import sys
from collections import Counter
from urllib.parse import unquote, urlparse

BRANDS = {
    "paypal": "paypal.com",
    "google": "google.com",
    "microsoft": "microsoft.com",
    "facebook": "facebook.com",
    "apple": "apple.com",
    "gcash": "gcash.com",
    "lexshield": "lexshield.com",
    "netflix": "netflix.com",
    "amazon": "amazon.com",
    "outlook": "outlook.com",
}

HOMOGLYPHS = str.maketrans(
    {
        "а": "a",
        "е": "e",
        "о": "o",
        "р": "p",
        "с": "c",
        "у": "y",
        "х": "x",
        "і": "i",
        "ѕ": "s",
        "ԁ": "d",
        "ɡ": "g",
        "0": "o",
        "1": "l",
        "3": "e",
        "4": "a",
        "5": "s",
        "7": "t",
        "8": "b",
    }
)

RISKY_WORDS = (
    "login",
    "signin",
    "verify",
    "password",
    "passwd",
    "update",
    "secure",
    "account",
    "confirm",
    "unlock",
    "suspend",
    "limited",
    "wallet",
    "payment",
)
QUERY_TRAPS = (
    "password",
    "passwd",
    "pwd",
    "token",
    "session",
    "email",
    "login",
    "redirect",
    "next",
    "return",
    "continue",
)
SUSPICIOUS_TLDS = {
    "zip",
    "mov",
    "click",
    "gq",
    "tk",
    "ml",
    "cf",
    "xyz",
    "top",
    "country",
    "example",
    "invalid",
    "test",
}


def shannon_entropy(value: str) -> float:
    if not value:
        return 0.0
    counts = Counter(value)
    length = float(len(value))
    return -sum((count / length) * math.log2(count / length) for count in counts.values())


def skeleton(value: str) -> str:
    return value.lower().translate(HOMOGLYPHS)


def host_labels(host: str) -> list[str]:
    return [part for part in host.lower().strip(".").split(".") if part]


def registrable_domain(host: str) -> str:
    labels = host_labels(host)
    if len(labels) <= 2:
        return ".".join(labels)
    return ".".join(labels[-2:])


def tokenize(value: str) -> set[str]:
    return {part for part in re.split(r"[^a-z0-9]+", value.lower()) if part}


def levenshtein(left: str, right: str) -> int:
    if left == right:
        return 0
    if not left:
        return len(right)
    if not right:
        return len(left)
    previous = list(range(len(right) + 1))
    for i, left_ch in enumerate(left, start=1):
        current = [i]
        for j, right_ch in enumerate(right, start=1):
            current.append(
                min(
                    previous[j] + 1,
                    current[j - 1] + 1,
                    previous[j - 1] + (left_ch != right_ch),
                )
            )
        previous = current
    return previous[-1]


def scan_url(url: str) -> dict:
    raw = (url or "").strip()
    findings: list[str] = []
    risk = 0

    if not raw:
        return {
            "status": "suspicious",
            "score": 0,
            "risk": 0,
            "message": "URL is required.",
            "findings": [],
            "engine": "python",
        }

    parsed = urlparse(raw)
    scheme = (parsed.scheme or "").lower()
    host = (parsed.hostname or "").lower()
    if scheme not in {"http", "https"} or host == "":
        return {
            "status": "suspicious",
            "score": 0,
            "risk": 0,
            "message": "Enter a valid http or https URL.",
            "findings": [],
            "engine": "python",
        }

    blob = raw.lower()
    domain = registrable_domain(host)
    labels = host_labels(host)
    tld = labels[-1] if labels else ""
    decoded_host = unquote(host)
    skel_host = skeleton(decoded_host)
    skel_domain = skeleton(domain)

    if scheme == "http":
        risk += 24
        findings.append("Python: the URL does not use HTTPS.")
    authority = raw.split("://", 1)[-1].split("/", 1)[0]
    if parsed.username or parsed.password or "@" in authority:
        risk += 26
        findings.append("Python: the URL hides a destination after an @ sign.")
    if re.fullmatch(r"\d{1,3}(?:\.\d{1,3}){3}", host) or host.startswith("0x"):
        risk += 28
        findings.append("Python: the host is a raw or hex IP address.")
    if any(ord(ch) > 127 for ch in host) or "xn--" in host:
        risk += 22
        findings.append("Python: the domain uses international or punycode characters.")
    if decoded_host != host and ("." in decoded_host or "/" in decoded_host):
        risk += 18
        findings.append("Python: the host uses percent-encoding to hide its real shape.")
    if tld in SUSPICIOUS_TLDS:
        risk += 16
        findings.append("Python: the top-level domain is commonly abused in scams.")
    if host.count("-") > 2 or "--" in host:
        risk += 10
        findings.append("Python: the domain uses unusual hyphen patterns.")
    if len(labels) >= 5:
        risk += 14
        findings.append("Python: the hostname is unusually nested.")
    if parsed.port not in (None, 80, 443):
        risk += 12
        findings.append("Python: the URL uses an uncommon network port.")
    if shannon_entropy(domain.split(".")[0]) >= 3.6 and len(domain.split(".")[0]) >= 10:
        risk += 14
        findings.append("Python: the domain name looks randomly generated.")

    query = (parsed.query or "").lower()
    trap_hits = [name for name in QUERY_TRAPS if re.search(rf"(^|&){name}=", query)]
    if len(trap_hits) >= 2:
        risk += 16
        findings.append("Python: the query string asks for login or redirect data.")

    path = (parsed.path or "").lower()
    if re.search(r"/(login|signin|verify|reset|password|account)(/|$)", path):
        risk += 8
        findings.append("Python: the path asks for login or account recovery.")

    tokens = tokenize(host + " " + path + " " + query)
    risky_hits = [word for word in RISKY_WORDS if word in blob]
    for brand, trusted in BRANDS.items():
        brand_skel = skeleton(brand)
        in_host = brand in tokens or brand_skel in tokenize(skel_host)
        looks_like = levenshtein(skel_domain.split(".")[0], brand_skel) == 1 and len(brand) >= 5
        trusted_match = domain == trusted or domain.endswith("." + trusted)
        if (in_host or looks_like) and not trusted_match:
            risk += 56
            findings.append(
                f"Python: '{brand}' is impersonated, but the real domain is '{domain}' rather than '{trusted}'."
            )
            if risky_hits:
                risk += 16
                findings.append("Python: brand impersonation is paired with login or payment language.")

    if len(risky_hits) >= 3:
        risk += 10
        findings.append("Python: the URL stacks several urgent account-action words.")

    if risk >= 55:
        status = "phishing"
        score = min(99, risk)
        message = findings[0] if findings else "Python found multiple phishing indicators."
    elif risk >= 25:
        status = "suspicious"
        score = risk
        message = findings[0] if findings else "Python found suspicious URL patterns."
    else:
        status = "safe"
        score = max(90, 100 - risk)
        message = "Python found no strong phishing indicators."

    return {
        "status": status,
        "score": score,
        "risk": risk,
        "message": message,
        "findings": findings,
        "engine": "python",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="LEXSHIELD Python phishing scanner")
    parser.add_argument("url", nargs="?", default="")
    parser.add_argument("--url", dest="url_flag", default="")
    args = parser.parse_args()
    url = args.url_flag or args.url
    if not url:
        try:
            payload = json.load(sys.stdin)
            url = str(payload.get("url") or "")
        except Exception:
            url = ""
    json.dump(scan_url(url), sys.stdout, ensure_ascii=True)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
