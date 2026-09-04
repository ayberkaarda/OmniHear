import { AnalysisStatus, FeedbackCategory, SentimentLabel } from '../../core/feedback/feedback.models';

/**
 * Localized text for the domain enums, for the places a badge cannot go —
 * `<option>` labels, table cells, `aria-label`s.
 *
 * `shared/ui/badge` localizes the same vocabulary inside its own template.
 * These carry their own message ids rather than reusing the badge's: an id is a
 * contract between a source string and its translation, and sharing one across
 * two files would make either file's wording change silently rewrite the other.
 */

export function sentimentLabel(value: SentimentLabel | string): string {
  switch (value) {
    case 'positive':
      return $localize`:Sentiment label@@label.sentiment.positive:Positive`;
    case 'neutral':
      return $localize`:Sentiment label@@label.sentiment.neutral:Neutral`;
    case 'negative':
      return $localize`:Sentiment label@@label.sentiment.negative:Negative`;
    default:
      return value;
  }
}

export function categoryLabel(value: FeedbackCategory | string): string {
  switch (value) {
    case 'complaint':
      return $localize`:Feedback category label@@label.category.complaint:Complaint`;
    case 'praise':
      return $localize`:Feedback category label@@label.category.praise:Praise`;
    case 'bug':
      return $localize`:Feedback category label@@label.category.bug:Bug`;
    case 'feature_request':
      return $localize`:Feedback category label@@label.category.featureRequest:Feature request`;
    default:
      return value;
  }
}

export function analysisStatusLabel(value: AnalysisStatus | string): string {
  switch (value) {
    case 'pending_analysis':
      return $localize`:Analysis status label@@label.analysisStatus.pending:Waiting for analysis`;
    case 'analyzing':
      return $localize`:Analysis status label@@label.analysisStatus.analyzing:Being analysed`;
    case 'analyzed':
      return $localize`:Analysis status label@@label.analysisStatus.analyzed:Analysed`;
    case 'failed':
      return $localize`:Analysis status label@@label.analysisStatus.failed:Analysis failed`;
    default:
      return value;
  }
}

/**
 * Platform names that are proper nouns, and therefore deliberately not
 * localized: "App Store" is "App Store" in Turkish too, and routing a brand
 * name through the catalogue would produce a trans-unit whose target can only
 * ever equal its source — which `i18n:check` rule 3 reads, correctly, as an
 * untranslated string.
 */
const PLATFORM_PROPER_NOUNS: Readonly<Record<string, string>> = {
  appstore: 'App Store',
  googleplay: 'Google Play',
  zendesk: 'Zendesk',
  trustpilot: 'Trustpilot',
  fixture: 'Fixture'
};

export function platformLabel(value: string): string {
  switch (value) {
    case 'email':
      return $localize`:Feedback source platform@@label.platform.email:Email`;
    case 'social':
      return $localize`:Feedback source platform@@label.platform.social:Social media`;
    default:
      return PLATFORM_PROPER_NOUNS[value] ?? value;
  }
}

/** Human name for a connector setting key (`app_id`, `country`, ...). */
export function settingLabel(key: string): string {
  switch (key) {
    case 'app_id':
      return $localize`:Connector setting field label@@label.setting.appId:Application ID`;
    case 'country':
      return $localize`:Connector setting field label@@label.setting.country:Country code`;
    case 'subdomain':
      return $localize`:Connector setting field label@@label.setting.subdomain:Subdomain`;
    case 'instance_url':
      return $localize`:Connector setting field label@@label.setting.instanceUrl:Instance URL`;
    case 'hashtag':
      return $localize`:Connector setting field label@@label.setting.hashtag:Hashtag`;
    default:
      return key;
  }
}

/**
 * Human name for a connector credential key.
 *
 * These name a field the user types *into*. Nothing labelled here is ever
 * rendered with a value next to it: the API does not return credentials, and
 * this application has no code path that could display one.
 */
export function credentialLabel(key: string): string {
  switch (key) {
    case 'email':
      return $localize`:Connector credential field label@@label.credential.email:Account email`;
    case 'api_token':
      return $localize`:Connector credential field label@@label.credential.apiToken:API token`;
    case 'session_url':
      return $localize`:Connector credential field label@@label.credential.sessionUrl:Session URL`;
    case 'mailbox':
      return $localize`:Connector credential field label@@label.credential.mailbox:Mailbox`;
    default:
      return key;
  }
}
