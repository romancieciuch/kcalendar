const CACHE_DB_NAME = 'kcalendar-cache';
const CACHE_STORE_NAME = 'accounts';

function openCacheDb() {
  if (!('indexedDB' in window)) return Promise.resolve(null);
  return new Promise(resolve => {
    const request = indexedDB.open(CACHE_DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(CACHE_STORE_NAME, { keyPath: 'uuid' });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => resolve(null);
    request.onblocked = () => resolve(null);
  });
}

async function cacheAccount(method, value) {
  const db = await openCacheDb();
  if (!db) return null;
  return new Promise(resolve => {
    const transaction = db.transaction(CACHE_STORE_NAME, method === 'get' ? 'readonly' : 'readwrite');
    const store = transaction.objectStore(CACHE_STORE_NAME);
    const request = method === 'get' ? store.get(value) : method === 'put' ? store.put(value) : store.delete(value);
    let result = null;
    request.onsuccess = () => { result = method === 'get' ? request.result || null : true; };
    transaction.onabort = () => { db.close(); resolve(null); };
    transaction.onerror = () => { db.close(); resolve(null); };
    transaction.oncomplete = () => { db.close(); resolve(result); };
  });
}

const cacheGet = uuid => cacheAccount('get', uuid);
const cachePut = document => cacheAccount('put', document);
const cacheDelete = uuid => cacheAccount('delete', uuid);
