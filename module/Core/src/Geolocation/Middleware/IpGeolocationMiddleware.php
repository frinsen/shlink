<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Geolocation\Middleware;

use Locale;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Common\Util\IpAddress;
use Shlinkio\Shlink\Core\Config\Options\TrackingOptions;
use Shlinkio\Shlink\IpGeolocation\Exception\WrongIpException;
use Shlinkio\Shlink\IpGeolocation\GeoLite2\DbUpdaterInterface;
use Shlinkio\Shlink\IpGeolocation\Model\Location;
use Shlinkio\Shlink\IpGeolocation\Resolver\IpLocationResolverInterface;
use Throwable;

use function is_numeric;
use function mb_substr;
use function preg_match;
use function Shlinkio\Shlink\Core\ipAddressFromRequest;
use function strtoupper;
use function trim;

readonly class IpGeolocationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private IpLocationResolverInterface $ipLocationResolver,
        private DbUpdaterInterface $dbUpdater,
        private LoggerInterface $logger,
        private TrackingOptions $trackingOptions,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->trackingOptions->isGeolocationRelevant()) {
            return $handler->handle($request);
        }

        $cloudflareLocation = $this->trackingOptions->cloudflareGeolocationEnabled
            ? $this->locationFromCloudflareHeaders($request)
            : null;
        if ($cloudflareLocation !== null) {
            return $handler->handle($request->withAttribute(Location::class, $cloudflareLocation));
        }

        if (!$this->dbUpdater->databaseFileExists()) {
            $this->logger->warning('Tried to geolocate IP address, but a GeoLite2 db was not found.');
            return $handler->handle($request);
        }

        $location = $this->geolocateIpAddress(ipAddressFromRequest($request));
        return $handler->handle($request->withAttribute(Location::class, $location));
    }

    /**
     * Builds a location from the headers Cloudflare adds when "IP Geolocation" (country only) or the "Add visitor
     * location headers" managed transform (country, region, city, coordinates and timezone) are enabled.
     * Returns null when the country is missing or is one of Cloudflare's special codes (XX unknown, T1 Tor), so
     * that GeoLite2 can be used instead.
     * @see https://developers.cloudflare.com/rules/transform/managed-transforms/reference/#add-visitor-location-headers
     */
    private function locationFromCloudflareHeaders(ServerRequestInterface $request): Location|null
    {
        $countryCode = strtoupper(trim($request->getHeaderLine('CF-IPCountry')));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1 || $countryCode === 'XX' || $countryCode === 'T1') {
            return null;
        }

        $countryName = Locale::getDisplayRegion('-' . $countryCode, 'en');
        return new Location(
            countryCode: $countryCode,
            countryName: $countryName !== false && $countryName !== $countryCode ? $countryName : '',
            regionName: $this->stringHeader($request, 'CF-Region'),
            city: $this->stringHeader($request, 'CF-IPCity'),
            latitude: $this->coordinateHeader($request, 'CF-IPLatitude', max: 90),
            longitude: $this->coordinateHeader($request, 'CF-IPLongitude', max: 180),
            timeZone: $this->stringHeader($request, 'CF-Timezone'),
        );
    }

    /**
     * Headers can be forged when Shlink is reachable without going through Cloudflare, so values are capped to the
     * length of the location columns they are persisted in
     */
    private function stringHeader(ServerRequestInterface $request, string $name): string
    {
        return mb_substr($request->getHeaderLine($name), 0, 255);
    }

    /**
     * Out-of-range values are discarded, as forged ones like "1e999" would otherwise be persisted as INF, which cannot
     * be JSON-encoded
     */
    private function coordinateHeader(ServerRequestInterface $request, string $name, int $max): float
    {
        $value = $request->getHeaderLine($name);
        if (!is_numeric($value)) {
            return 0.0;
        }

        $coordinate = (float) $value;
        return $coordinate >= -$max && $coordinate <= $max ? $coordinate : 0.0;
    }

    private function geolocateIpAddress(string|null $ipAddress): Location
    {
        try {
            return $ipAddress === null || $ipAddress === IpAddress::LOCALHOST
                ? Location::empty()
                : $this->ipLocationResolver->resolveIpLocation($ipAddress);
        } catch (WrongIpException $e) {
            $this->logger->warning('Tried to locate IP address, but it seems to be wrong. {e}', ['e' => $e]);
            return Location::empty();
        } catch (Throwable $e) {
            $this->logger->error('An unexpected error occurred while trying to locate IP address. {e}', ['e' => $e]);
            return Location::empty();
        }
    }
}
