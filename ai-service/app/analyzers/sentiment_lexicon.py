"""Deterministic TR/EN lexicon sentiment backend.

This is the no-weights fallback described in
:mod:`app.analyzers.sentiment`. It is a valence lexicon with three
modifier rules, which is roughly the VADER recipe minus the tuned
constants:

* **Negation.** A negator flips the polarity of nearby terms. The window
  is asymmetric on purpose: English negates *before* the term ("not
  good"), Turkish frequently negates *after* it ("iyi değil", "sorun
  yok"), so the scan looks both ways with different reaches.
* **Intensifiers / diminishers.** "çok berbat" is more negative than
  "berbat"; "biraz yavaş" is less negative than "yavaş". These only apply
  to a following term.
* **Saturation.** The raw sum is squashed by ``net / (|net| + k)`` rather
  than clipped, so a review with eight complaints does not read as
  exactly as negative as one with three, but neither runs off the scale.

Every lexicon entry is matched against both the case-folded token and its
diacritic-folded form, because Turkish app-store reviews are routinely
written without diacritics ("cok kotu" for "çok kötü").
"""

import hashlib
from typing import Final

from app.analyzers.sentiment import SentimentOutcome, label_for
from app.analyzers.text import fold_diacritics, tokenize

BACKEND_ID: Final = "lex"

# Squashing constant. Larger values need more evidence to reach a strong
# score; 2.0 puts a single strong term at ~0.33 and three at ~0.6.
_SATURATION_K = 2.0

# How far a negator reaches. Backwards is longer because Turkish postposes
# its negators ("uygulama hic iyi degil" — "degil" is three tokens after
# "iyi").
_NEGATION_LOOKBACK = 3
_NEGATION_LOOKAHEAD = 3

_NEGATION_FACTOR = -0.85
_INTENSIFIER_FACTOR = 1.6
_DIMINISHER_FACTOR = 0.5

_POSITIVE_TR: Final = {
    "harika": 0.9,
    "mukemmel": 1.0,
    "muhtesem": 0.9,
    "super": 0.8,
    "guzel": 0.6,
    "iyi": 0.5,
    "basarili": 0.6,
    "hizli": 0.5,
    "kolay": 0.5,
    "kullanisli": 0.7,
    "faydali": 0.6,
    "yararli": 0.6,
    "sevdim": 0.8,
    "begendim": 0.8,
    "bayildim": 0.9,
    "tesekkurler": 0.6,
    "tesekkur": 0.5,
    "tebrikler": 0.7,
    "memnun": 0.7,
    "memnunum": 0.8,
    "tavsiye": 0.6,
    "pratik": 0.6,
    "temiz": 0.5,
    "akici": 0.6,
    "sorunsuz": 0.7,
    "stabil": 0.5,
    "keyifli": 0.7,
    "efsane": 0.8,
    "sahane": 0.9,
    "kusursuz": 0.9,
    "essiz": 0.7,
    "kaliteli": 0.7,
    "sade": 0.4,
    "anlasilir": 0.5,
    "rahat": 0.5,
    "hosuma": 0.6,
    "helal": 0.7,
    "eline": 0.4,
    "saglik": 0.4,
    "vazgecilmez": 0.8,
    "isime": 0.5,
    "yaradi": 0.5,
    "onerilir": 0.6,
}

_NEGATIVE_TR: Final = {
    "berbat": -0.9,
    "kotu": -0.7,
    "rezalet": -1.0,
    "felaket": -0.9,
    "cop": -0.9,
    "yavas": -0.6,
    "donuyor": -0.8,
    "donduruyor": -0.8,
    "cokuyor": -0.9,
    "coktu": -0.8,
    "hata": -0.6,
    "hatali": -0.7,
    "sorun": -0.6,
    "sorunlu": -0.7,
    "bug": -0.6,
    "kasiyor": -0.7,
    "acilmiyor": -0.9,
    "calismiyor": -0.9,
    "yuklenmiyor": -0.8,
    "bozuk": -0.8,
    "sacma": -0.7,
    "pahali": -0.6,
    "igrenc": -1.0,
    "dolandirici": -1.0,
    "dolandiricilik": -1.0,
    "kandirmaca": -0.9,
    "iade": -0.5,
    "sikayet": -0.6,
    "sinir": -0.6,
    "sinirli": -0.5,
    "kizgin": -0.6,
    "vasat": -0.5,
    "kullanissiz": -0.8,
    "karmasik": -0.5,
    "reklam": -0.4,
    "reklamlar": -0.5,
    "yetersiz": -0.6,
    "eksik": -0.5,
    "gereksiz": -0.6,
    "pisman": -0.8,
    "kayboldu": -0.7,
    "silindi": -0.6,
    "cekilmiyor": -0.7,
    "beklemek": -0.3,
    "beklettiler": -0.6,
    "cevap": -0.2,
    "berbatti": -0.9,
    "zorluk": -0.5,
    "zor": -0.4,
    "hayal": -0.3,
    "kirikligi": -0.7,
    "aksiyor": -0.7,
}

_POSITIVE_EN: Final = {
    "great": 0.8,
    "excellent": 0.9,
    "amazing": 0.9,
    "awesome": 0.9,
    "love": 0.8,
    "loved": 0.8,
    "perfect": 0.9,
    "best": 0.8,
    "good": 0.5,
    "nice": 0.5,
    "fast": 0.5,
    "easy": 0.5,
    "useful": 0.6,
    "helpful": 0.6,
    "beautiful": 0.7,
    "smooth": 0.6,
    "reliable": 0.6,
    "recommend": 0.6,
    "fantastic": 0.9,
    "wonderful": 0.9,
    "brilliant": 0.8,
    "flawless": 0.9,
    "intuitive": 0.6,
    "clean": 0.4,
    "solid": 0.5,
    "lifesaver": 0.9,
    "thanks": 0.4,
    "thank": 0.4,
    "impressed": 0.7,
    "delightful": 0.8,
    "polished": 0.6,
    "stable": 0.5,
    "worth": 0.5,
    "favorite": 0.7,
    "favourite": 0.7,
    "outstanding": 0.9,
}

_NEGATIVE_EN: Final = {
    "terrible": -0.9,
    "awful": -0.9,
    "horrible": -0.9,
    "worst": -1.0,
    "bad": -0.6,
    "hate": -0.8,
    "useless": -0.8,
    "slow": -0.6,
    "crash": -0.8,
    "crashes": -0.8,
    "crashed": -0.8,
    "crashing": -0.8,
    "buggy": -0.7,
    "broken": -0.8,
    "freeze": -0.7,
    "freezes": -0.7,
    "frozen": -0.7,
    "error": -0.6,
    "errors": -0.6,
    "fail": -0.7,
    "fails": -0.7,
    "failed": -0.7,
    "annoying": -0.7,
    "disappointed": -0.8,
    "disappointing": -0.8,
    "refund": -0.6,
    "scam": -1.0,
    "garbage": -0.9,
    "trash": -0.9,
    "unusable": -0.9,
    "laggy": -0.7,
    "lag": -0.6,
    "expensive": -0.5,
    "overpriced": -0.7,
    "ads": -0.4,
    "spam": -0.6,
    "unresponsive": -0.7,
    "glitchy": -0.7,
    "glitch": -0.6,
    "waste": -0.8,
    "stuck": -0.6,
    "missing": -0.4,
    "wrong": -0.5,
    "confusing": -0.6,
    "impossible": -0.6,
    "ridiculous": -0.7,
    "frustrating": -0.8,
    "regret": -0.8,
}

_LEXICON: Final = {**_POSITIVE_TR, **_NEGATIVE_TR, **_POSITIVE_EN, **_NEGATIVE_EN}

_NEGATORS: Final = frozenset(
    """
    degil degildi yok hic asla hayir olmuyor olmadi yapmiyor edilmiyor
    not no never none cannot cant dont doesnt didnt isnt arent wasnt
    wont couldnt shouldnt without nor neither
    """.split()
)

_INTENSIFIERS: Final = frozenset(
    """
    cok asiri son derece gercekten harbiden inanilmaz oldukca fazla resmen
    very really extremely so super totally absolutely incredibly highly
    completely utterly quite too
    """.split()
)

_DIMINISHERS: Final = frozenset(
    """
    biraz az hafif nispeten kismen
    slightly somewhat kinda kind sort barely marginally
    """.split()
)


class LexiconSentimentBackend:
    """Rule-based sentiment scorer. No weights, no runtime dependencies."""

    @property
    def backend_id(self) -> str:
        return BACKEND_ID

    @property
    def fingerprint(self) -> str:
        """Digest over the lexicon and the modifier constants.

        Editing a single polarity weight changes this, and therefore
        changes ``model_version`` — which is the whole point: analyses
        produced before the edit become identifiable and reprocessable.
        """
        digest = hashlib.sha256()
        for name, table in (
            ("lexicon", _LEXICON),
            ("negators", dict.fromkeys(sorted(_NEGATORS), 0.0)),
            ("intensifiers", dict.fromkeys(sorted(_INTENSIFIERS), 0.0)),
            ("diminishers", dict.fromkeys(sorted(_DIMINISHERS), 0.0)),
        ):
            digest.update(name.encode("utf-8"))
            for key in sorted(table):
                digest.update(f"{key}={table[key]!r};".encode())
        constants = (
            _SATURATION_K,
            _NEGATION_LOOKBACK,
            _NEGATION_LOOKAHEAD,
            _NEGATION_FACTOR,
            _INTENSIFIER_FACTOR,
            _DIMINISHER_FACTOR,
        )
        digest.update(repr(constants).encode("utf-8"))
        return digest.hexdigest()

    def score(self, text: str, language: str) -> SentimentOutcome:
        """Score `text`. `language` is accepted for interface parity and
        deliberately unused: the lexicon is bilingual and a wrong language
        label must not silently disable half of it."""
        tokens = [fold_diacritics(token) for token in tokenize(text)]

        net = 0.0
        hits = 0
        for index, token in enumerate(tokens):
            weight = _LEXICON.get(token)
            if weight is None:
                continue
            hits += 1
            net += weight * self._modifier(tokens, index)

        if hits == 0:
            return SentimentOutcome(score=0.0, label=label_for(0.0), confidence=0.25)

        score = round(net / (abs(net) + _SATURATION_K), 4)
        label = label_for(score)
        confidence = round(min(0.95, 0.35 + 0.5 * abs(score) + 0.05 * hits), 4)
        return SentimentOutcome(score=score, label=label, confidence=confidence)

    @staticmethod
    def _modifier(tokens: list[str], index: int) -> float:
        """Combined negation/intensification factor applying at `index`."""
        factor = 1.0

        preceding = tokens[max(0, index - _NEGATION_LOOKBACK) : index]
        following = tokens[index + 1 : index + 1 + _NEGATION_LOOKAHEAD]
        if any(token in _NEGATORS for token in preceding) or any(
            token in _NEGATORS for token in following
        ):
            factor *= _NEGATION_FACTOR

        # Only a directly preceding modifier counts; "very" two words away
        # usually modifies something else.
        if index > 0:
            previous = tokens[index - 1]
            if previous in _INTENSIFIERS:
                factor *= _INTENSIFIER_FACTOR
            elif previous in _DIMINISHERS:
                factor *= _DIMINISHER_FACTOR

        return factor
