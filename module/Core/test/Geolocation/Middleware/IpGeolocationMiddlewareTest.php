<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Geolocation\Middleware;

use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shlinkio\Shlink\Common\Util\IpAddress;
use Shlinkio\Shlink\Core\Config\Options\TrackingOptions;
use Shlinkio\Shlink\Core\Geolocation\Middleware\IpGeolocationMiddleware;
use Shlinkio\Shlink\IpGeolocation\Exception\WrongIpException;
use Shlinkio\Shlink\IpGeolocation\GeoLite2\DbUpdaterInterface;
use Shlinkio\Shlink\IpGeolocation\Model\Location;
use Shlinkio\Shlink\IpGeolocation\Resolver\IpLocationResolverInterface;
use Throwable;

use function str_repeat;

use const Shlinkio\Shlink\IP_ADDRESS_REQUEST_ATTRIBUTE;

class IpGeolocationMiddlewareTest extends TestCase
{
    private MockObject&IpLocationResolverInterface $ipLocationResolver;
    private MockObject&DbUpdaterInterface $dbUpdater;
    private MockObject&LoggerInterface $logger;
    private MockObject&RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $this->ipLocationResolver = $this->createMock(IpLocationResolverInterface::class);
        $this->dbUpdater = $this->createMock(DbUpdaterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);
    }

    #[Test]
    public function geolocationIsSkippedIfTrackingIsDisabled(): void
    {
        $this->dbUpdater->expects($this->never())->method('databaseFileExists');
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');
        $this->logger->expects($this->never())->method('warning');

        $request = ServerRequestFactory::fromGlobals();
        $this->handler->expects($this->once())->method('handle')->with($request);

        $this->middleware(disableTracking: true)->process($request, $this->handler);
    }

    #[Test]
    public function warningIsLoggedIfGeoLiteDbDoesNotExist(): void
    {
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');
        $this->dbUpdater->expects($this->once())->method('databaseFileExists')->willReturn(false);
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Tried to geolocate IP address, but a GeoLite2 db was not found.',
            );

        $request = ServerRequestFactory::fromGlobals();
        $this->handler->expects($this->once())->method('handle')->with($request);

        $this->middleware()->process($request, $this->handler);
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([IpAddress::LOCALHOST])]
    public function emptyLocationIsReturnedIfIpAddressIsNotLocatable(string|null $ipAddress): void
    {
        $this->dbUpdater->expects($this->once())->method('databaseFileExists')->willReturn(true);
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');
        $this->logger->expects($this->never())->method('warning');

        $request = ServerRequestFactory::fromGlobals()->withAttribute(IP_ADDRESS_REQUEST_ATTRIBUTE, $ipAddress);
        $this->handler
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(
                static function (ServerRequestInterface $req): bool {
                    $location = $req->getAttribute(Location::class);
                    if (!$location instanceof Location) {
                        return false;
                    }

                    Assert::assertEmpty($location->countryCode);
                    return true;
                },
            ));

        $this->middleware()->process($request, $this->handler);
    }

    #[Test]
    public function locationIsResolvedFromIpAddress(): void
    {
        $this->dbUpdater->expects($this->once())->method('databaseFileExists')->willReturn(true);
        $this->ipLocationResolver
            ->expects($this->once())
            ->method('resolveIpLocation')
            ->with('1.2.3.4')
            ->willReturn(
                new Location(countryCode: 'ES'),
            );
        $this->logger->expects($this->never())->method('warning');

        $request = ServerRequestFactory::fromGlobals()->withAttribute(IP_ADDRESS_REQUEST_ATTRIBUTE, '1.2.3.4');
        $this->handler
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(
                static function (ServerRequestInterface $req): bool {
                    $location = $req->getAttribute(Location::class);
                    if (!$location instanceof Location) {
                        return false;
                    }

                    Assert::assertEquals('ES', $location->countryCode);
                    return true;
                },
            ));

        $this->middleware()->process($request, $this->handler);
    }

    /**
     * @param non-empty-string $loggerMethod
     */
    #[Test]
    #[TestWith([
        new WrongIpException(),
        'warning',
        'Tried to locate IP address, but it seems to be wrong. {e}',
    ])]
    #[TestWith([
        new RuntimeException('Unknown'),
        'error',
        'An unexpected error occurred while trying to locate IP address. {e}',
    ])]
    public function warningIsPrintedIfAnErrorOccurs(
        Throwable $exception,
        string $loggerMethod,
        string $expectedLoggedMessage,
    ): void {
        $this->dbUpdater->expects($this->once())->method('databaseFileExists')->willReturn(true);
        $this->ipLocationResolver
            ->expects($this->once())
            ->method('resolveIpLocation')
            ->with('1.2.3.4')
            ->willThrowException($exception);
        $this->logger->expects($this->once())->method($loggerMethod)->with($expectedLoggedMessage, ['e' => $exception]);

        $request = ServerRequestFactory::fromGlobals()->withAttribute(IP_ADDRESS_REQUEST_ATTRIBUTE, '1.2.3.4');
        $this->handler
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(
                static function (ServerRequestInterface $req): bool {
                    $location = $req->getAttribute(Location::class);
                    if (!$location instanceof Location) {
                        return false;
                    }

                    Assert::assertEmpty($location->countryCode);
                    return true;
                },
            ));

        $this->middleware()->process($request, $this->handler);
    }

    #[Test]
    public function locationIsResolvedFromCloudflareHeadersWhenEnabled(): void
    {
        $this->dbUpdater->expects($this->never())->method('databaseFileExists');
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');
        $this->logger->expects($this->never())->method('warning');

        $request = ServerRequestFactory::fromGlobals()
            ->withAttribute(IP_ADDRESS_REQUEST_ATTRIBUTE, '1.2.3.4')
            ->withHeader('CF-IPCountry', 'DE')
            ->withHeader('CF-Region', 'Berlin')
            ->withHeader('CF-IPCity', 'Berlin')
            ->withHeader('CF-IPLatitude', '52.52000')
            ->withHeader('CF-IPLongitude', '13.40500')
            ->withHeader('CF-Timezone', 'Europe/Berlin');
        $this->handler
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(
                static function (ServerRequestInterface $req): bool {
                    Assert::assertEquals(new Location(
                        countryCode: 'DE',
                        countryName: 'Germany',
                        regionName: 'Berlin',
                        city: 'Berlin',
                        latitude: 52.52,
                        longitude: 13.405,
                        timeZone: 'Europe/Berlin',
                    ), $req->getAttribute(Location::class));
                    return true;
                },
            ));

        $this->middleware(cloudflareGeolocationEnabled: true)->process($request, $this->handler);
    }

    #[Test]
    public function onlyCountryIsSetWhenCloudflareSendsNoOtherLocationHeaders(): void
    {
        $this->dbUpdater->expects($this->never())->method('databaseFileExists');
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');
        $this->logger->expects($this->never())->method('warning');

        $request = ServerRequestFactory::fromGlobals()->withHeader('CF-IPCountry', 'es');
        $this->handler
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(
                static function (ServerRequestInterface $req): bool {
                    Assert::assertEquals(
                        new Location(countryCode: 'ES', countryName: 'Spain'),
                        $req->getAttribute(Location::class),
                    );
                    return true;
                },
            ));

        $this->middleware(cloudflareGeolocationEnabled: true)->process($request, $this->handler);
    }

    #[Test]
    public function cloudflareLocationHeadersAreCappedToColumnLength(): void
    {
        $this->dbUpdater->expects($this->never())->method('databaseFileExists');
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');
        $this->logger->expects($this->never())->method('warning');

        $tooLong = str_repeat('ü', 300);
        $request = ServerRequestFactory::fromGlobals()
            ->withHeader('CF-IPCountry', 'DE')
            ->withHeader('CF-Region', $tooLong)
            ->withHeader('CF-IPCity', $tooLong)
            ->withHeader('CF-Timezone', $tooLong);
        $this->handler
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(
                static function (ServerRequestInterface $req): bool {
                    $expected = str_repeat('ü', 255);
                    Assert::assertEquals(new Location(
                        countryCode: 'DE',
                        countryName: 'Germany',
                        regionName: $expected,
                        city: $expected,
                        timeZone: $expected,
                    ), $req->getAttribute(Location::class));
                    return true;
                },
            ));

        $this->middleware(cloudflareGeolocationEnabled: true)->process($request, $this->handler);
    }

    #[Test]
    #[TestWith(['1e999', '-1e999'], 'infinite')]
    #[TestWith(['90.1', '180.1'], 'above range')]
    #[TestWith(['-90.1', '-180.1'], 'below range')]
    #[TestWith(['north', ''], 'not numeric')]
    public function invalidCloudflareCoordinatesAreDiscarded(string $latitude, string $longitude): void
    {
        $this->dbUpdater->expects($this->never())->method('databaseFileExists');
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');
        $this->logger->expects($this->never())->method('warning');

        $request = ServerRequestFactory::fromGlobals()
            ->withHeader('CF-IPCountry', 'DE')
            ->withHeader('CF-IPLatitude', $latitude)
            ->withHeader('CF-IPLongitude', $longitude);
        $this->handler
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(
                static function (ServerRequestInterface $req): bool {
                    Assert::assertEquals(
                        new Location(countryCode: 'DE', countryName: 'Germany'),
                        $req->getAttribute(Location::class),
                    );
                    return true;
                },
            ));

        $this->middleware(cloudflareGeolocationEnabled: true)->process($request, $this->handler);
    }

    #[Test]
    #[TestWith([true, null], 'header missing')]
    #[TestWith([true, 'XX'], 'unknown country')]
    #[TestWith([true, 'T1'], 'Tor')]
    #[TestWith([true, 'not-a-country'], 'invalid value')]
    #[TestWith([false, 'DE'], 'cloudflare geolocation disabled')]
    public function geoLiteIsUsedWhenCloudflareHeadersAreNotUsable(
        bool $cloudflareGeolocationEnabled,
        string|null $countryHeader,
    ): void {
        $this->dbUpdater->expects($this->once())->method('databaseFileExists')->willReturn(true);
        $this->ipLocationResolver
            ->expects($this->once())
            ->method('resolveIpLocation')
            ->with('1.2.3.4')
            ->willReturn(new Location(countryCode: 'FR'));
        $this->logger->expects($this->never())->method('warning');

        $request = ServerRequestFactory::fromGlobals()->withAttribute(IP_ADDRESS_REQUEST_ATTRIBUTE, '1.2.3.4');
        if ($countryHeader !== null) {
            $request = $request->withHeader('CF-IPCountry', $countryHeader);
        }
        $this->handler
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(
                static function (ServerRequestInterface $req): bool {
                    $location = $req->getAttribute(Location::class);
                    if (!$location instanceof Location) {
                        return false;
                    }

                    Assert::assertEquals('FR', $location->countryCode);
                    return true;
                },
            ));

        $this->middleware(cloudflareGeolocationEnabled: $cloudflareGeolocationEnabled)->process(
            $request,
            $this->handler,
        );
    }

    #[Test]
    public function cloudflareHeadersAreIgnoredIfTrackingIsDisabled(): void
    {
        $this->dbUpdater->expects($this->never())->method('databaseFileExists');
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');
        $this->logger->expects($this->never())->method('warning');
        $request = ServerRequestFactory::fromGlobals()->withHeader('CF-IPCountry', 'DE');
        $this->handler->expects($this->once())->method('handle')->with($request);

        $this->middleware(disableTracking: true, cloudflareGeolocationEnabled: true)->process(
            $request,
            $this->handler,
        );
    }

    private function middleware(
        bool $disableTracking = false,
        bool $cloudflareGeolocationEnabled = false,
    ): IpGeolocationMiddleware {
        return new IpGeolocationMiddleware(
            $this->ipLocationResolver,
            $this->dbUpdater,
            $this->logger,
            new TrackingOptions(
                disableTracking: $disableTracking,
                cloudflareGeolocationEnabled: $cloudflareGeolocationEnabled,
            ),
        );
    }
}
