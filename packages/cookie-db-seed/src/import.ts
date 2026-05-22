// OpenCookieDatabase importer.
//
// Source: https://github.com/jkwakman/Open-Cookie-Database (CC0)
//
// Stub. Implementation will:
//   1. Fetch the upstream CSV
//   2. Parse rows into CanonicalCookie shape
//   3. POST JSONL to apps/api `/api/internal/cookie-db/staging`
//      (Laravel writes to staging table for human review before promotion)

const OPEN_COOKIE_DB_URL =
  'https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.csv';

const main = async (): Promise<void> => {
  console.info(`[cookie-db-seed] would fetch ${OPEN_COOKIE_DB_URL}`);
  console.info('[cookie-db-seed] importer not yet implemented');
};

void main();
