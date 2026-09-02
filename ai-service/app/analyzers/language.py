"""Turkish/English language detection.

Scope, stated up front because it is a real limitation: this detector
*discriminates* Turkish from English. It does not identify arbitrary
languages. OmniHear is a TR-market product whose second language is
English (spec §4: the UI ships tr/en only), so those are the two labels
the pipeline can produce on its own. For any other language the caller's
``language_hint`` is the only source of truth, and it is honoured when
detection is inconclusive.

The scoring is a weighted three-signal vote:

* **Orthography.** ``ğ ı ş`` (and the vowels ``ç ö ü â î û``) do not occur
  in English text. A single dotless ``ı`` is worth much more evidence than
  a single common word.
* **Function words.** Closed-class words — articles, conjunctions,
  postpositions — are the classic language-ID signal because they are
  frequent and untranslated in code-switched text.
* **Morphology.** Turkish is agglutinative: ``-yor``, ``-mış``, ``-ecek``,
  ``-lar`` and friends are highly diagnostic suffixes with no English
  counterpart, which is what keeps short diacritic-free reviews
  ("uygulama cok yavas calisiyor") on the Turkish side.

`language_hint` deliberately does **not** override a confident detection.
The hint arrives from platform metadata (an App Store storefront locale,
say), which is routinely wrong — a review written in English sits under a
``tr`` storefront all the time. Detection wins when it has evidence; the
hint only breaks ties.
"""

from dataclasses import dataclass

from app.analyzers.text import fold_diacritics, has_letters, tokenize

DEFAULT_LANGUAGE = "en"

# Turkish letters absent from written English. "ı" and "ğ" and "ş" are the
# strongest of these; "ç ö ü" also appear in loanwords and other languages,
# so they carry less weight.
_TR_STRONG_CHARS = frozenset("ığş")
_TR_WEAK_CHARS = frozenset("çöüâîû")

_TR_STRONG_CHAR_WEIGHT = 2.5
_TR_WEAK_CHAR_WEIGHT = 0.8

# Closed-class Turkish words. Kept diacritic-free where the folded form is
# unambiguous, because lookups happen on the folded token as well.
_TR_FUNCTION_WORDS = frozenset(
    """
    ve veya ama ancak fakat cunku ki de da ile icin gibi kadar sonra once
    bir bu su o bunu sunu onu bana sana ona bizim sizin onlarin
    cok az daha en hic hep her bazi biraz yine hala zaten sadece
    var yok degil evet hayir tamam belki nasil neden nicin ne kim nerede
    ise iken diye ya ta te mi mu mı mü
    benim senin onun bende sende onda bize size onlara
    olarak uzere ragmen dolayi sayede yerine
    """.split()
)

# High-frequency English function words.
_EN_FUNCTION_WORDS = frozenset(
    """
    the a an and or but because that this these those there here
    is are was were be been being am do does did doing done
    have has had having will would can could should shall may might must
    i you he she it we they me him her us them my your his its our their
    of to in on at for from with without about into over under after before
    not no yes very just also too only even still always never
    what when where which who whom why how
    """.split()
)

_FUNCTION_WORD_WEIGHT = 1.0

# Open-class words that are frequent in Turkish product feedback and are
# not English words. Function words alone leave a one-word review such as
# "harika" with no evidence at all, and one-word reviews are common; a
# small language-specific vocabulary is the standard remedy. Kept
# diacritic-free, since lookups also run on the folded token.
_TR_CONTENT_WORDS = frozenset(
    """
    uygulama uygulamayi uygulamanin ekran ekrani bildirim bildirimler
    guncelleme guncellemeden surum ozellik ozelligi ayarlar destek
    kullanici kullanim abonelik odeme iade fatura kargo siparis
    harika guzel kotu berbat mukemmel muhtesem rezalet efsane sahane
    tesekkurler tesekkur tebrikler lutfen sorun hata hatali yavas hizli
    kolay zor pahali ucretsiz reklam reklamlar sikayet begendim sevdim
    tavsiye memnun calisiyor calismiyor acilmiyor donuyor cokuyor
    ekleyin ekleyebilir isterdim istiyorum bekliyorum sevinirim
    """.split()
)

_TR_CONTENT_WORD_WEIGHT = 1.2

# The Turkish present continuous always contains "-Iyor". Matched as a
# substring rather than a suffix because person endings follow it
# ("bekliyorum", "bekliyoruz", "calisiyorlar"), which a suffix test would
# miss. The preceding vowel is required so that English "mayor" and
# "surveyor" do not match.
_TR_PROGRESSIVE_INFIXES = ("iyor", "uyor", "ıyor", "üyor")
_TR_PROGRESSIVE_WEIGHT = 2.0

# Agglutinative suffixes with no English counterpart. Ordered longest-first
# so that "-iyor" is preferred over "-yor" when both would match.
_TR_SUFFIXES: tuple[tuple[str, float], ...] = (
    ("iyorum", 2.0),
    ("iyorsun", 2.0),
    ("miyor", 2.0),
    ("muyor", 2.0),
    ("iyor", 2.0),
    ("uyor", 2.0),
    ("yorum", 2.0),
    ("acagim", 1.6),
    ("ecegim", 1.6),
    ("acak", 1.4),
    ("ecek", 1.4),
    ("misti", 1.4),
    ("mistir", 1.4),
    ("lardan", 1.4),
    ("lerden", 1.4),
    ("larin", 1.2),
    ("lerin", 1.2),
    ("mizi", 1.2),
    ("niz", 1.0),
    ("dir", 0.8),
    ("tir", 0.8),
    ("mis", 1.0),
    ("dim", 1.2),
    ("dik", 1.0),
    ("lar", 0.9),
    ("ler", 0.9),
)

# Minimum suffix-bearing stem length, so that "her" is not read as "-er".
_MIN_SUFFIX_STEM = 3

# Suffixes subject to Turkish vowel harmony: "-lar" attaches after a back
# vowel, "-ler" after a front vowel. Checking this rejects English
# agentive nouns such as "smaller" and "cooler", whose stem vowel
# disagrees with the apparent suffix.
_HARMONY_SUFFIXES = {"lar": "back", "ler": "front", "lardan": "back", "lerden": "front"}
_BACK_VOWELS = frozenset("aiou")  # "ı" has already folded to "i" here
_FRONT_VOWELS = frozenset("eiou")

# English words frequent in app-store reviews whose ending coincides with a
# Turkish suffix and survives the harmony check. A short, explicit denylist
# is cheaper and far more predictable than shipping an English dictionary.
_EN_SUFFIX_COLLISIONS = frozenset(
    """
    seller sellers killer killers filler fillers simpler settler traveller
    similar popular regular singular circular particular spectacular
    scholar cellar collar stellar burglar calendar
    smaller cooler dealer earlier speaker cheaper bigger
    premis dismis
    """.split()
)

# Below this total score there is not enough evidence to call it; the hint
# (or the default) takes over.
_DECISION_THRESHOLD = 1.0

# Margin between the two scores needed before the winner is called
# "confident" — reported for observability, not used for the decision.
_CONFIDENT_MARGIN = 2.0


@dataclass(frozen=True)
class LanguageDetection:
    """Outcome of one detection, including why it came out that way."""

    language: str
    confidence: float
    used_hint: bool
    tr_score: float
    en_score: float


def normalize_hint(language_hint: str | None) -> str | None:
    """Reduce a caller-supplied hint to a bare ISO 639-1 code, or None.

    Accepts ``tr``, ``TR``, ``tr-TR`` and ``tr_TR`` — platform connectors
    supply all four shapes. Anything else is rejected rather than
    truncated: naively taking the first two characters turns the junk
    value ``"not-a-language"`` into ``"no"``, and the service would then
    confidently report Norwegian.
    """
    if not language_hint:
        return None

    candidate = language_hint.strip()
    if len(candidate) > 2 and candidate[2] not in "-_":
        return None

    prefix = candidate[:2].lower()
    if len(prefix) == 2 and prefix.isascii() and prefix.isalpha():
        return prefix
    return None


def detect(text: str, language_hint: str | None = None) -> LanguageDetection:
    """Detect the language of `text`, falling back to `language_hint`."""
    hint = normalize_hint(language_hint)

    if not has_letters(text):
        # Emoji-only, digits-only or punctuation-only: no linguistic
        # evidence exists at all, so there is nothing to override.
        return LanguageDetection(
            language=hint or DEFAULT_LANGUAGE,
            confidence=0.0,
            used_hint=hint is not None,
            tr_score=0.0,
            en_score=0.0,
        )

    tr_score, en_score = _score(text)
    total = tr_score + en_score

    if total < _DECISION_THRESHOLD:
        return LanguageDetection(
            language=hint or DEFAULT_LANGUAGE,
            confidence=0.0,
            used_hint=hint is not None,
            tr_score=tr_score,
            en_score=en_score,
        )

    language = "tr" if tr_score > en_score else "en"
    margin = abs(tr_score - en_score)
    confidence = min(1.0, margin / _CONFIDENT_MARGIN) * min(1.0, total / _CONFIDENT_MARGIN)

    return LanguageDetection(
        language=language,
        confidence=round(confidence, 4),
        used_hint=False,
        tr_score=round(tr_score, 4),
        en_score=round(en_score, 4),
    )


def _score(text: str) -> tuple[float, float]:
    """Return (turkish_score, english_score) for `text`."""
    tr_score = 0.0
    en_score = 0.0

    for char in text:
        lowered = char.lower()
        if lowered in _TR_STRONG_CHARS:
            tr_score += _TR_STRONG_CHAR_WEIGHT
        elif lowered in _TR_WEAK_CHARS:
            tr_score += _TR_WEAK_CHAR_WEIGHT

    for token in tokenize(text):
        folded = fold_diacritics(token)
        if token in _TR_FUNCTION_WORDS or folded in _TR_FUNCTION_WORDS:
            tr_score += _FUNCTION_WORD_WEIGHT
        elif token in _EN_FUNCTION_WORDS:
            en_score += _FUNCTION_WORD_WEIGHT
        elif folded in _TR_CONTENT_WORDS:
            tr_score += _TR_CONTENT_WORD_WEIGHT
        elif any(infix in folded for infix in _TR_PROGRESSIVE_INFIXES):
            tr_score += _TR_PROGRESSIVE_WEIGHT
        else:
            tr_score += _suffix_score(folded)

    return tr_score, en_score


def _suffix_score(folded_token: str) -> float:
    """Weight of the longest Turkish suffix `folded_token` ends with.

    Returns 0.0 for known English collisions and for plural suffixes whose
    stem violates Turkish vowel harmony.
    """
    if folded_token in _EN_SUFFIX_COLLISIONS:
        return 0.0

    for suffix, weight in _TR_SUFFIXES:
        if len(folded_token) < len(suffix) + _MIN_SUFFIX_STEM:
            continue
        if not folded_token.endswith(suffix):
            continue
        if not _harmony_ok(folded_token, suffix):
            return 0.0
        return weight
    return 0.0


def _harmony_ok(folded_token: str, suffix: str) -> bool:
    """True when the stem's last vowel agrees with the suffix's vowel class."""
    required = _HARMONY_SUFFIXES.get(suffix)
    if required is None:
        return True

    stem = folded_token[: -len(suffix)]
    allowed = _BACK_VOWELS if required == "back" else _FRONT_VOWELS
    for char in reversed(stem):
        if char in _BACK_VOWELS or char in _FRONT_VOWELS:
            return char in allowed
    return True
