-- rotchist_trade_offers
--
-- Every trade OFFER in league history, not just the ones that happened.
--
-- Source is MFL's commissioner-only Trade Offers report
-- (/{year}/options?O=03&TYPE=TRADE_OFFERS&FRANCHISE=0000), scraped by
-- tools/trade_offers_backfill.py and loaded by tools/trade_offers_ingest.php.
-- It is NOT available from the public export API: TYPE=transactions returns
-- only "non-pending transactions", so rejected/revoked/expired offers exist
-- nowhere else. Accepted trades appear in both, and their per-season counts
-- match exactly -- that agreement is the scrape's validation check.
--
-- Coverage is 2016-2026. MFL recycles league ids per year, so league_id is
-- stored per row: 2016 is league 46283, 2017+ is 67102, and those same ids
-- belong to unrelated leagues in other years. Pre-2016 seasons would each
-- need their own league id discovered via TYPE=myleagues.

CREATE TABLE IF NOT EXISTS rotchist_trade_offers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  season            SMALLINT UNSIGNED NOT NULL,
  league_id         VARCHAR(10) NOT NULL,

  -- 'proposal' is the offer being made; the other four are what became of
  -- it. An offer typically produces two rows: the proposal, then its
  -- outcome. 'accepted' rows are the same events the public API exposes
  -- as TRANS_TYPE=TRADE.
  status            ENUM('proposal','accepted','rejected','revoked','expired') NOT NULL,

  -- MFL franchise ids as they appear that season ('0006'), plus the
  -- resolved stable identity where the ingest could match it. Both are kept
  -- for the same reason rotchist_mfl_games keeps both: franchise names and
  -- ids drift, and a report should still render if resolution fails.
  proposer_mfl_id   CHAR(4) NOT NULL,
  recipient_mfl_id  CHAR(4) NOT NULL,
  proposer_id       INT UNSIGNED NULL,
  recipient_id      INT UNSIGNED NULL,

  -- Asset lists as MFL renders them, e.g.
  -- "Jeanty, Ashton LVR RB ; Year 2026 Draft Pick 3.08".
  -- Left as text deliberately: these are display strings covering players,
  -- current and future draft picks and blind-bid dollars, and splitting
  -- them into a normalised asset table would need a player-id resolver
  -- that this data does not carry.
  proposer_gave     TEXT NULL,
  recipient_gave    TEXT NULL,

  -- The offer's trash-talk note ("And give me 30 auction dollars"). Rare.
  reason            VARCHAR(1000) NULL,

  occurred_at       DATETIME NOT NULL,
  -- Offer deadline. Only proposals and outcomes that carried one have it;
  -- accepted trades often do not.
  expires_at        DATETIME NULL,

  -- sha1 over the identifying fields, so re-running the ingest updates in
  -- place instead of duplicating. The report has no stable row id of its
  -- own, hence a content hash.
  row_hash          CHAR(40) NOT NULL,

  ingested_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row (row_hash),
  KEY idx_season (season),
  KEY idx_status (status),
  KEY idx_season_status (season, status),
  KEY idx_proposer (season, proposer_mfl_id),
  KEY idx_recipient (season, recipient_mfl_id),
  KEY idx_occurred (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
